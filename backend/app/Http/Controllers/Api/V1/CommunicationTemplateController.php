<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RenderCommunicationTemplateRequest;
use App\Http\Requests\StoreCommunicationTemplateRequest;
use App\Http\Requests\UpdateCommunicationTemplateRequest;
use App\Http\Resources\CommunicationTemplateResource;
use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Reusable wording for the composer (module 03 slice S5, spec 11, ADR-0017 §10).
 *
 * A template is the **house's wording**: every role reads and renders one into the log/reply
 * form, only manager+ authors or retires one. Rendering is a prefill, never a write — what is
 * saved is an ordinary `Communication`, so a template edit can never rewrite history.
 */
class CommunicationTemplateController extends Controller
{
    /**
     * The template book (manager+ view; every role reads).
     *
     * Ordered active-first, then by name: the picker's order, so a retired template sits at the
     * bottom rather than being invisible.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommunicationTemplate::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $templates = CommunicationTemplate::query()
            ->with('author:id,name')
            ->when($filters['channel'] ?? null, fn ($query, $value) => $query->whereIn(
                'channel',
                array_values(array_filter(explode(',', (string) $value))),
            ))
            // `filter[active]=false` is a real request, so the key's presence decides — not its truthiness.
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where(
                'is_active',
                filter_var($filters['active'], FILTER_VALIDATE_BOOL),
            ))
            ->search($request->string('search')->toString() ?: null)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return CommunicationTemplateResource::collection($templates);
    }

    /**
     * Author a template (manager+).
     */
    public function store(StoreCommunicationTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $template = CommunicationTemplate::create([
            'name' => $data['name'],
            'channel' => $data['channel'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        return (new CommunicationTemplateResource($template->load('author:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CommunicationTemplate $template): CommunicationTemplateResource
    {
        $this->authorize('view', $template);

        return new CommunicationTemplateResource($template->load('author:id,name'));
    }

    /**
     * Edit a template (manager+). Every field is optional; changing one cannot touch a message
     * it was ever rendered into, because nothing points back here (ADR-0017 §10).
     */
    public function update(UpdateCommunicationTemplateRequest $request, CommunicationTemplate $template): CommunicationTemplateResource
    {
        $template->update($request->validated());

        return new CommunicationTemplateResource($template->load('author:id,name'));
    }

    /**
     * Retire a template (manager+, soft). It leaves the composer's picker; the row — and its name
     * — stay for the record.
     */
    public function destroy(CommunicationTemplate $template): Response
    {
        $this->authorize('delete', $template);

        $template->delete();

        return response()->noContent();
    }

    public function restore(string $id): CommunicationTemplateResource
    {
        $template = CommunicationTemplate::withTrashed()->findOrFail($id);

        $this->authorize('restore', $template);

        // A name is unique among *live* templates: if one has taken this name since, restoring
        // would leave two templates answering to one label in the picker.
        $taken = CommunicationTemplate::query()
            ->whereKeyNot($template->getKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($template->name)])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => ['Another template already uses that name, so this one cannot come back under it.'],
            ]);
        }

        $template->restore();

        return new CommunicationTemplateResource($template->load('author:id,name'));
    }

    /**
     * The merge-field vocabulary (spec 11 §S5), so the composer's chips and the renderer cannot
     * drift apart: the server offers only tokens it can actually fill.
     */
    public function fields(): JsonResponse
    {
        $this->authorize('viewAny', CommunicationTemplate::class);

        return response()->json([
            'data' => collect(CommunicationTemplate::mergeFields())
                ->map(fn (string $label, string $token): array => ['token' => $token, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * Render a template against a record, for the composer to prefill from.
     *
     * The record must be one the caller may **see**: rendering reads its own details, so being
     * able to name it is not enough. Anything the record cannot fill is left in the text and
     * reported in `unresolved` — the server never invents a value.
     */
    public function render(RenderCommunicationTemplateRequest $request, CommunicationTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        $data = $request->validated();
        $subject = $this->resolveSubject($data['subject_type'], $data['subject_id']);

        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => ['That record does not exist.'],
            ]);
        }

        $this->authorize('view', $subject);

        $contact = null;

        if (isset($data['contact_id'])) {
            $contact = Contact::query()->find($data['contact_id']);

            if ($contact === null) {
                throw ValidationException::withMessages([
                    'contact_id' => ['That contact does not exist.'],
                ]);
            }

            // A merge field is a small disclosure — the caller must be able to see the person
            // they are being filled in with, not merely name them (spec 11 §S5).
            $this->authorize('view', $contact);
        }

        /** @var User $user */
        $user = $request->user();
        $rendered = $template->render($subject, $contact, $user);

        return response()->json([
            'data' => [
                'template_id' => $template->id,
                'channel' => $rendered['channel'],
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'unresolved' => $rendered['unresolved'],
            ],
        ]);
    }

    /**
     * The record a template is rendered against — the same morph map messages use.
     */
    private function resolveSubject(string $type, string $id): Lead|Company|Contact|Opportunity|Contract|null
    {
        $class = Communication::subjectTypes()[$type] ?? null;

        return $class === null ? null : $class::query()->find($id);
    }
}
