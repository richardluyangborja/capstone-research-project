<?php

namespace Database\Seeders;

use App\Enums\CommunicationChannel;
use App\Models\CommunicationTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fixed demo templates (no faker — seeded for the proposal demo, module 03 slice S5).
 *
 * The house's wording, so the composer's picker renders from a real record and the merge
 * fields fill from whatever the message is pointed at. The `Holiday greeting` is seeded
 * **retired** (`is_active = false`) so the template book's active filter has something to hide.
 */
class DemoCommunicationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::query()->where('email', 'manager@primepower.test')->first();

        foreach ($this->templates() as $template) {
            CommunicationTemplate::create($template + ['created_by' => $manager?->id]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'name' => 'Rate sheet follow-up',
                'channel' => CommunicationChannel::Email,
                'subject' => 'Our updated rate sheet for {{company.name}}',
                'body' => "Hi {{contact.first_name}},\n\n"
                    .'Thank you for the time today. I am sending over our updated rate sheet for '
                    .'{{company.name}}, covering the roles you flagged and the current market rates '
                    ."for each.\n\n"
                    .'If it helps, I can walk {{contact.first_name}} through the numbers and put '
                    ."together a shortlist this week.\n\n"
                    ."Best regards,\n"
                    ."{{user.name}}\n"
                    .'{{user.email}}',
                'is_active' => true,
            ],
            [
                'name' => 'Site visit confirmation',
                'channel' => CommunicationChannel::Email,
                'subject' => 'Site visit confirmed — {{company.name}}',
                'body' => "Hi {{contact.first_name}},\n\n"
                    .'Confirming our visit to {{company.name}} on {{today}}. I will bring the '
                    .'service agreement and the requirements checklist so we can finalise the '
                    ."headcount on the day.\n\n"
                    ."Please let me know if {{contact.position}} would like to join the walk-through.\n\n"
                    ."See you then,\n"
                    .'{{user.name}}',
                'is_active' => true,
            ],
            [
                'name' => 'Cold call script',
                'channel' => CommunicationChannel::Call,
                'subject' => 'First call — {{company.name}}',
                'body' => 'Opening: "Good morning, this is {{user.name}} from Primepower Manpower '
                    ."Services. May I speak with {{contact.name}}?\"\n\n"
                    .'Reason for calling: we supply trained manpower for {{company.name}} and I '
                    ."wanted to ask how you are covering your current openings.\n\n"
                    ."Questions: headcount needed, roles, target start date, who signs off.\n\n"
                    .'Close: offer a short site visit or send the rate sheet the same day.',
                'is_active' => true,
            ],
            [
                'name' => 'Meeting agenda',
                'channel' => CommunicationChannel::Meeting,
                'subject' => 'Agenda — {{company.name}} manpower review',
                'body' => "Attendees: {{contact.name}} ({{contact.position}}) and {{user.name}}.\n\n"
                    ."1. Current requirements and headcount.\n"
                    ."2. Rates and contract terms.\n"
                    ."3. Deployment schedule and site requirements.\n"
                    ."4. Next steps and owner per action.\n\n"
                    .'Prepared {{today}}.',
                'is_active' => true,
            ],
            [
                'name' => 'Holiday greeting',
                'channel' => CommunicationChannel::Email,
                'subject' => 'Season\'s greetings from Primepower Manpower Services',
                'body' => "Hi {{contact.first_name}},\n\n"
                    .'Warmest wishes from all of us at Primepower Manpower Services to the team at '
                    ."{{company.name}}. Thank you for working with us this year.\n\n"
                    .'{{user.name}}',
                'is_active' => false,
            ],
        ];
    }
}
