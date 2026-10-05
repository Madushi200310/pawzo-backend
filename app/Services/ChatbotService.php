<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    /**
     * Generate a response for the given user message.
     *
     * @return array{message: string, source: string}
     */
    public function respond(string $userMessage): array
    {
        $normalized = strtolower(trim($userMessage));

        // 1. Try rule-based match first
        $ruleResponse = $this->matchRule($normalized);

        if ($ruleResponse !== null) {
            return [
                'message' => $ruleResponse,
                'source'  => 'rule',
            ];
        }

        // 2. Try OpenAI if configured
        if (config('services.openai.api_key')) {
            $aiResponse = $this->askOpenAi($userMessage);
            if ($aiResponse !== null) {
                return [
                    'message' => $aiResponse,
                    'source'  => 'openai',
                ];
            }
        }

        // 3. Fallback response
        return [
            'message' => "I'm not sure about that yet. 🐾 For specific concerns, please consult a veterinarian. You can also ask me about: vaccination, feeding, grooming, training, health, or lost pets.",
            'source'  => 'fallback',
        ];
    }

    /**
     * Rule-based pattern matching.
     */
    protected function matchRule(string $text): ?string
    {
        $rules = $this->getRules();

        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $rule['response'];
                }
            }
        }

        return null;
    }

    /**
     * Rule definitions.
     */
    protected function getRules(): array
    {
        return [
            [
                'keywords' => ['vaccination', 'vaccine', 'vaccinate', 'එන්නත්'],
                'response' => "**Vaccination Schedule for Pets** 💉\n\n"
                    . "• **Puppies/Kittens**: Start at 6-8 weeks, then every 3-4 weeks until 16 weeks\n"
                    . "• **Adult dogs**: Annual boosters (rabies, DHPP, leptospirosis)\n"
                    . "• **Adult cats**: Annual boosters (FVRCP, rabies)\n\n"
                    . "Always consult your vet for the exact schedule based on your pet's age and health.",
            ],
            [
                'keywords' => ['feeding', 'feed', 'food', 'how much to feed', 'diet', 'ආහාර'],
                'response' => "**Feeding Guidelines** 🍽️\n\n"
                    . "• **Puppies** (under 6 months): 3-4 small meals/day\n"
                    . "• **Adult dogs**: 2 meals/day\n"
                    . "• **Kittens** (under 6 months): 3-4 meals/day\n"
                    . "• **Adult cats**: 2 meals/day + fresh water always\n\n"
                    . "Portion depends on weight, breed, and activity. Check the food packaging or ask your vet.",
            ],
            [
                'keywords' => ['grooming', 'groom', 'bath', 'brush', 'නාන'],
                'response' => "**Grooming Tips** ✂️\n\n"
                    . "• **Bathing**: Every 4-6 weeks (dogs), every 6-8 weeks (cats)\n"
                    . "• **Brushing**: 2-3 times/week (long coats need daily)\n"
                    . "• **Nail trimming**: Every 3-4 weeks\n"
                    . "• **Ear cleaning**: Weekly check\n\n"
                    . "Use pet-safe shampoos — never human products!",
            ],
            [
                'keywords' => ['training', 'train', 'obedience', 'command', 'පුහුණු'],
                'response' => "**Basic Training Tips** 🎓\n\n"
                    . "• **Start early** — 8-16 weeks is ideal\n"
                    . "• **Short sessions** — 5-10 min, 2-3 times/day\n"
                    . "• **Positive reinforcement** — treats + praise\n"
                    . "• **Basic commands**: sit, stay, come, down\n"
                    . "• **Consistency** is key — same commands, same rewards",
            ],
            [
                'keywords' => ['health', 'sick', 'ill', 'vomit', 'diarrhea', 'fever', 'අසනීප'],
                'response' => "**When to See a Vet** 🏥\n\n"
                    . "⚠️ **Emergency signs**:\n"
                    . "• Persistent vomiting or diarrhea\n"
                    . "• Difficulty breathing\n"
                    . "• Blood in stool/urine\n"
                    . "• Refusing food for over 24 hours\n"
                    . "• Sudden collapse or seizures\n\n"
                    . "For mild symptoms, monitor for 24 hours. If it worsens, see a vet immediately.",
            ],
            [
                'keywords' => ['lost', 'missing', 'run away', 'නැති'],
                'response' => "**Lost Pet — Action Plan** 🔍\n\n"
                    . "1. **Post on Pawzo's Lost & Found** immediately\n"
                    . "2. Search nearby — pets usually hide close by\n"
                    . "3. Put up posters with recent photo\n"
                    . "4. Alert local vets and shelters\n"
                    . "5. Post on social media (local groups)\n"
                    . "6. Check at night — pets are more active\n\n"
                    . "Don't give up — many pets are found weeks later!",
            ],
            [
                'keywords' => ['adopt', 'adoption', 'rescue', 'හදාගන්න'],
                'response' => "**Adoption Guide** 🏠\n\n"
                    . "• **Consider lifestyle** — energy level, space, time\n"
                    . "• **Meet the pet** multiple times before deciding\n"
                    . "• **Health check** — ask for vaccination records\n"
                    . "• **Prepare home** — food, bed, toys, crate\n"
                    . "• **Give time** — 3 days to adjust, 3 weeks to settle, 3 months to feel home\n\n"
                    . "Adopting saves lives! 🐾",
            ],
            [
                'keywords' => ['hello', 'hi', 'hey', 'ayubowan', 'හලෝ'],
                'response' => "Hello! 👋 I'm Pawzo AI, your pet care assistant. Ask me about:\n\n"
                    . "• 🐕 Training & behavior\n"
                    . "• 💉 Vaccination\n"
                    . "• 🍽️ Feeding\n"
                    . "• ✂️ Grooming\n"
                    . "• 🏥 Health concerns\n"
                    . "• 🔍 Lost pets\n"
                    . "• 🏠 Adoption",
            ],
            [
                'keywords' => ['thank', 'thanks', 'ස්තූති'],
                'response' => "You're welcome! 🐾 If you have more questions, just ask. Happy pet parenting! 💙",
            ],
        ];
    }

    /**
     * Call OpenAI Chat Completions API.
     */
    protected function askOpenAi(string $userMessage): ?string
    {
        $apiKey = config('services.openai.api_key');
        $model  = config('services.openai.model', 'gpt-4o-mini');

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'      => $model,
                    'messages'   => [
                        [
                            'role'    => 'system',
                            'content' => 'You are Pawzo AI, a helpful and friendly pet care assistant. '
                                . 'Answer pet-related questions clearly and concisely. '
                                . 'If asked about medical emergencies, advise seeing a veterinarian.',
                        ],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens'  => 300,
                    'temperature' => 0.7,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            Log::warning('OpenAI API failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;

        } catch (\Throwable $e) {
            Log::error('OpenAI API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}