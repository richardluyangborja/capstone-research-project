<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyInvitationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Http\Resources\SurveyResponseResource;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The client's page (module 05 slice S2, spec 13 §Business rules 6).
 *
 * The one unauthenticated surface in the CRM: the token in the link is the credential, so the
 * page returns only the survey being asked and never the account's other data. An unknown token
 * is 404; one that can no longer be answered — submitted, expired, cancelled or failed — is 410
 * with the reason the page shows. The token dies with the submission.
 */
class PublicSurveyController extends Controller
{
    /**
     * The questions, as the client will see them. Nothing about the account travels beyond the
     * name the greeting uses.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->resolve($token);

        return response()->json([
            'data' => [
                'survey' => [
                    'id' => $invitation->survey->id,
                    'name' => $invitation->survey->name,
                    'description' => $invitation->survey->description,
                    'questions' => $invitation->survey->questions
                        ->map(fn (SurveyQuestion $question): array => [
                            'id' => $question->id,
                            'type' => $question->type->value,
                            'prompt' => $question->prompt,
                            'help' => $question->help,
                            'required' => (bool) $question->required,
                            'options' => $question->options,
                            'bounds' => $question->type->numericBounds(),
                        ])
                        ->values(),
                ],
                'company' => ['name' => $invitation->company->name],
                'contact' => $invitation->contact === null ? null : ['name' => $invitation->contact->name],
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Store the answers once. The invitation is re-read under a row lock so two submissions
     * racing on one token cannot both land: the second sees `submitted` and gets 410.
     */
    public function submit(SubmitSurveyResponseRequest $request): JsonResponse
    {
        $invitation = $request->invitation();

        if ($invitation === null) {
            abort(404, 'This survey link is not valid.');
        }

        if (! $invitation->isAnswerable()) {
            throw $this->unavailable($invitation);
        }

        $response = DB::transaction(function () use ($invitation, $request): SurveyResponse {
            $fresh = SurveyInvitation::query()
                ->with('survey')
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                abort(404, 'This survey link is not valid.');
            }

            if (! $fresh->isAnswerable()) {
                throw $this->unavailable($fresh);
            }

            return SurveyResponse::record($fresh, $request->answerRows());
        });

        return response()->json([
            'data' => new SurveyResponseResource(
                $response->load(['survey:id,name', 'company:id,name', 'contact:id,name', 'invitation:id,reference']),
            ),
            'message' => 'Thank you — your answers have been sent to Primepower Manpower Services.',
        ], 201);
    }

    /**
     * The invitation a token names, with what the page renders. Throttled at the router.
     */
    private function resolve(string $token): SurveyInvitation
    {
        $invitation = SurveyInvitation::query()
            ->with(['survey.questions', 'company', 'contact'])
            ->where('token', $token)
            ->first();

        if ($invitation === null) {
            abort(404, 'This survey link is not valid.');
        }

        if (! $invitation->isAnswerable()) {
            throw $this->unavailable($invitation);
        }

        return $invitation;
    }

    /**
     * Why the link no longer works — said plainly, because a client is reading it.
     */
    private function unavailable(SurveyInvitation $invitation): HttpException
    {
        $reason = match ($invitation->state()) {
            SurveyInvitationState::Submitted => 'This survey has already been answered — thank you.',
            SurveyInvitationState::Expired => 'This survey link has expired.',
            SurveyInvitationState::Cancelled => 'This survey invitation was withdrawn.',
            SurveyInvitationState::Failed => 'This survey could not be delivered.',
            default => 'This survey is not available.',
        };

        return new HttpException(410, $reason);
    }
}
