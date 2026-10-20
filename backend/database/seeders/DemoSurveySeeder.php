<?php

namespace Database\Seeders;

use App\Enums\SurveyQuestionType;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fixed demo surveys (no faker — seeded for the proposal demo, module 05 slice S1).
 *
 * The library the later slices read from: a **post-deployment check-in** and a **quarterly
 * account review** that a manager can send right away, an **exit survey**, a pilot that is still a
 * **draft** (inactive) and a **retired** referral pulse, so the book's active filter and
 * **Restore** demo from real rows without retiring something live first.
 */
class DemoSurveySeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::query()->where('email', 'manager@primepower.test')->first();

        foreach ($this->surveys() as $survey) {
            $questions = $survey['questions'];
            unset($survey['questions']);

            $record = Survey::create($survey + ['created_by' => $manager?->id]);

            foreach ($questions as $index => $question) {
                $record->questions()->create($question + ['position' => $index + 1]);
            }
        }

        // Retired through the same soft delete the API uses, so the row is out of the sendable
        // list and findable only under `filter[retired]` (spec 13 §Business rules 11).
        Survey::query()->where('name', 'Client referral pulse')->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function surveys(): array
    {
        return [
            [
                'name' => 'Post-deployment check-in',
                'description' => 'Sent after the first weeks of a deployment, to hear how the workers '
                    .'and the account are settling in.',
                'is_active' => true,
                'questions' => [
                    [
                        'type' => SurveyQuestionType::Rating,
                        'prompt' => 'How satisfied are you with the deployment?',
                        'help' => 'From the workers’ first day on site to how the account is managed today.',
                        'required' => true,
                    ],
                    [
                        'type' => SurveyQuestionType::Nps,
                        'prompt' => 'How likely are you to recommend Primepower to another company?',
                        'help' => '0 = not at all likely, 10 = extremely likely.',
                        'required' => true,
                    ],
                    [
                        'type' => SurveyQuestionType::Text,
                        'prompt' => 'Anything we should know?',
                        'help' => 'A concern, a compliment, or something we could do better.',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'Quarterly account review',
                'description' => 'The regular temperature check with an active client: the crew, the '
                    .'supervisor and the headcount.',
                'is_active' => true,
                'questions' => [
                    [
                        'type' => SurveyQuestionType::Rating,
                        'prompt' => 'How satisfied are you with the workers we deployed?',
                        'required' => true,
                    ],
                    [
                        'type' => SurveyQuestionType::Rating,
                        'prompt' => 'How satisfied are you with your account supervisor?',
                        'required' => true,
                    ],
                    [
                        'type' => SurveyQuestionType::SingleChoice,
                        'prompt' => 'How is the headcount matching your needs?',
                        'required' => true,
                        'options' => [
                            'Short of what we need',
                            'Just right',
                            'More than we need',
                        ],
                    ],
                    [
                        'type' => SurveyQuestionType::Text,
                        'prompt' => 'What should we keep doing, or change?',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'Exit survey',
                'description' => 'Sent when a client ends an engagement, so the reason is on record '
                    .'rather than guessed at.',
                'is_active' => true,
                'questions' => [
                    [
                        'type' => SurveyQuestionType::Rating,
                        'prompt' => 'Overall, how satisfied were you with Primepower?',
                        'required' => true,
                    ],
                    [
                        'type' => SurveyQuestionType::SingleChoice,
                        'prompt' => 'What is the main reason you are ending the engagement?',
                        'required' => true,
                        'options' => [
                            'Found another agency',
                            'Hired the workers directly',
                            'Budget cut',
                            'Closed the site',
                            'Other',
                        ],
                    ],
                    [
                        'type' => SurveyQuestionType::Text,
                        'prompt' => 'Would you tell us more?',
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'Deployment pulse (pilot)',
                'description' => 'A short pulse still being written — inactive until the questions are agreed.',
                'is_active' => false,
                'questions' => [
                    [
                        'type' => SurveyQuestionType::Rating,
                        'prompt' => 'How is the first week going?',
                        'required' => true,
                    ],
                ],
            ],
            [
                'name' => 'Client referral pulse',
                'description' => 'A retired pulse from the referral push.',
                'is_active' => true,
                'questions' => [
                    [
                        'type' => SurveyQuestionType::SingleChoice,
                        'prompt' => 'Have you referred us to another company?',
                        'required' => true,
                        'options' => [
                            'Yes, more than once',
                            'Yes, once',
                            'Not yet',
                        ],
                    ],
                    [
                        'type' => SurveyQuestionType::Text,
                        'prompt' => 'Who else could we help?',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }
}
