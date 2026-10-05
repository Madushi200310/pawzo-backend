<?php

namespace App\Services;

class PetNameGeneratorService
{
    /**
     * Style-keyed pools of names.
     * Each style has 'male', 'female', and 'neutral' pools.
     */
    private const POOLS = [
        'Cute' => [
            'male'    => ['Buddy', 'Charlie', 'Teddy', 'Milo', 'Coco', 'Pip', 'Biscuit', 'Peanut', 'Noodle', 'Waffle', 'Pepper', 'Mochi', 'Jelly', 'Pudding', 'Ziggy', 'Boba', 'Pumpkin', 'Cookie', 'Doodle', 'Sprout'],
            'female'  => ['Luna', 'Bella', 'Daisy', 'Rosie', 'Poppy', 'Molly', 'Lily', 'Peaches', 'Honey', 'Cupcake', 'Cherry', 'Minnie', 'Pixie', 'Bonbon', 'Toffee', 'Dumpling', 'Pebbles', 'Tilly', 'Gigi', 'Fifi'],
            'neutral' => ['Cuddles', 'Snuggles', 'Truffle', 'Muffin', 'Wiggles', 'Button', 'Nibbles', 'Gizmo', 'Tater', 'Bean'],
        ],
        'Cool' => [
            'male'    => ['Ace', 'Blaze', 'Dash', 'Fang', 'Ghost', 'Hunter', 'Jax', 'Koda', 'Leon', 'Maverick', 'Nitro', 'Onyx', 'Rex', 'Rogue', 'Shadow', 'Storm', 'Titan', 'Vega', 'Wolf', 'Zane'],
            'female'  => ['Aria', 'Echo', 'Fury', 'Indie', 'Jade', 'Kira', 'Maya', 'Nova', 'Raven', 'Sage', 'Scout', 'Willow', 'Zara', 'Zephyr', 'Rogue', 'Storm', 'Vega', 'Onyx', 'Sable', 'Reign'],
            'neutral' => ['Ash', 'Dusty', 'Felix', 'Kai', 'Nox', 'Quill', 'Slate', 'Zen', 'Rex', 'Blaze'],
        ],
        'Royal' => [
            'male'    => ['Alexander', 'Balthazar', 'Caesar', 'Caspian', 'Duke', 'Edmund', 'Edward', 'Ferdinand', 'George', 'Henry', 'Leopold', 'Maximilian', 'Napoleon', 'Oliver', 'Philip', 'Reginald', 'Rupert', 'Sebastian', 'Victor', 'Winston'],
            'female'  => ['Alexandra', 'Anastasia', 'Beatrice', 'Charlotte', 'Cleopatra', 'Eleanor', 'Elizabeth', 'Genevieve', 'Isabella', 'Josephine', 'Katherine', 'Margaret', 'Ophelia', 'Penelope', 'Seraphina', 'Theodora', 'Victoria', 'Wilhelmina', 'Zenobia', 'Arabella'],
            'neutral' => ['Majesty', 'Noble', 'Regal', 'Royal', 'Sovereign', 'Crown', 'Scepter', 'Monarch', 'Empire', 'Duchess'],
        ],
        'Funny' => [
            'male'    => ['Sir Barks-a-Lot', 'Waffles', 'Noodle', 'Pickles', 'Sausage', 'Meatball', 'Chunky', 'Tater Tot', 'Cheeto', 'Dumpling', 'Biscuit', 'Beans', 'Nugget', 'Spud', 'Booger', 'Wobbles', 'Scooter', 'Nacho', 'Cheddar', 'Fartacus'],
            'female'  => ['Princess Fluff', 'Miss Wiggles', 'Diva', 'Sassy', 'Bubbles', 'Tofu', 'Pudding', 'Cupcake', 'Muffin', 'Pinky', 'Snickerdoodle', 'Sparkles', 'Tinkerbell', 'Boba', 'Macaron', 'Sushi', 'Biscotti', 'Waffle', 'Noodles', 'Piglet'],
            'neutral' => ['Potato', 'Chairman Meow', 'Sandwich', 'Nugget', 'Bean', 'Pickle', 'Waffle', 'Mashed Potato', 'Bologna', 'Spaghetti'],
        ],
        'Sinhala' => [
            'male'    => ['Kalu', 'Sudu', 'Raja', 'Singho', 'Podi', 'Loku', 'Chuti', 'Kolla', 'Bandara', 'Ralahamy', 'Weda', 'Sunil', 'Kamal', 'Nimal', 'Ruwan', 'Saman', 'Chandana', 'Ajith', 'Roshan', 'Chamara'],
            'female'  => ['Menike', 'Kumari', 'Nandani', 'Sanduni', 'Dilani', 'Iresha', 'Mala', 'Chandi', 'Rangi', 'Nilmini', 'Sewwandi', 'Hasini', 'Tharushi', 'Kavindi', 'Dinithi', 'Sachini', 'Nethmi', 'Hiruni', 'Piumi', 'Rashmi'],
            'neutral' => ['Podi', 'Chuti', 'Loku', 'Kalu', 'Sudu', 'Rathu', 'Nila', 'Baba', 'Bola', 'Gembi'],
        ],
        'English' => [
            'male'    => ['James', 'William', 'Henry', 'Oliver', 'Jack', 'Harry', 'George', 'Thomas', 'Edward', 'Arthur', 'Alfred', 'Charles', 'Frederick', 'Benjamin', 'Daniel', 'Samuel', 'Joseph', 'David', 'Robert', 'Michael'],
            'female'  => ['Emma', 'Olivia', 'Sophia', 'Charlotte', 'Amelia', 'Isabella', 'Mia', 'Evelyn', 'Harper', 'Luna', 'Camila', 'Gianna', 'Aurora', 'Penelope', 'Layla', 'Riley', 'Nora', 'Lily', 'Hazel', 'Violet'],
            'neutral' => ['Bailey', 'Riley', 'Morgan', 'Casey', 'Jordan', 'Taylor', 'Avery', 'Quinn', 'Rowan', 'Sage'],
        ],
        'Unique' => [
            'male'    => ['Zephyrion', 'Nyxar', 'Kaelo', 'Orin', 'Vesper', 'Axelith', 'Draven', 'Sylas', 'Torin', 'Caspian', 'Zephyr', 'Onyxis', 'Kael', 'Orion', 'Atlas', 'Zenith', 'Sirius', 'Phoenix', 'Eclipse', 'Lynx'],
            'female'  => ['Seraphine', 'Aurelia', 'Elowen', 'Saoirse', 'Isolde', 'Vespera', 'Lyra', 'Valkyrie', 'Nyx', 'Anouk', 'Freya', 'Selene', 'Ariadne', 'Calliope', 'Ophelia', 'Xanthe', 'Yara', 'Zelda', 'Ilaria', 'Nyra'],
            'neutral' => ['Echo', 'Vesper', 'Nyx', 'Rune', 'Sage', 'Zenith', 'Sol', 'Lux', 'Onyx', 'Kai'],
        ],
        'Food-inspired' => [
            'male'    => ['Nacho', 'Biscuit', 'Taco', 'Mango', 'Pretzel', 'Pickle', 'Bagel', 'Cheddar', 'Waffle', 'Ramen', 'Sushi', 'Noodle', 'Pesto', 'Espresso', 'Churro', 'Muffin', 'Popcorn', 'Pepper', 'Boba', 'Curry'],
            'female'  => ['Cupcake', 'Honey', 'Peaches', 'Cherry', 'Mochi', 'Pudding', 'Cookie', 'Pancake', 'Marshmallow', 'Macaron', 'Tiramisu', 'Cheesecake', 'Brownie', 'Jellybean', 'Cinnamon', 'Nutmeg', 'Ginger', 'Vanilla', 'Sundae', 'Bubblegum'],
            'neutral' => ['Toast', 'Nugget', 'Tofu', 'Sushi', 'Waffle', 'Muffin', 'Bagel', 'Bean', 'Tater', 'Biscuit'],
        ],
    ];

    /**
     * Type-based accent pools (small flavor additions).
     */
    private const TYPE_FLAVOR = [
        'dog'    => ['Bark', 'Fetch', 'Paw', 'Tail', 'Wag', 'Bone'],
        'cat'    => ['Meow', 'Whisker', 'Purr', 'Paw', 'Tabby'],
        'bird'   => ['Feather', 'Sky', 'Chirp', 'Wing', 'Tweet'],
        'rabbit' => ['Hop', 'Fluff', 'Carrot', 'Bounce'],
        'fish'   => ['Bubble', 'Coral', 'Fin', 'Scale', 'Wave'],
    ];

    /**
     * Large fallback pool, used only if a style can't fill 20 on its own.
     */
    private const FALLBACK = [
        'Buddy', 'Max', 'Bella', 'Luna', 'Charlie', 'Lucy', 'Rocky', 'Daisy',
        'Milo', 'Coco', 'Bailey', 'Zoe', 'Bear', 'Sadie', 'Duke', 'Ruby',
        'Apollo', 'Stella', 'Zeus', 'Nala', 'Finn', 'Willow', 'Kobe', 'Penny',
        'Oscar', 'Rosie', 'Buster', 'Ginger', 'Marley', 'Hazel', 'Thor', 'Honey',
        'Sammy', 'Piper', 'Rex', 'Maya', 'Toby', 'Poppy', 'Ryder', 'Juno',
        'Rusty', 'Missy', 'Louie', 'Zara', 'Bandit', 'Winnie', 'Koda', 'Belle',
    ];

    /**
     * Generate 20 names based on the given criteria.
     *
     * @return array{names: array<int, string>}
     */
    public function generate(string $type, string $gender, ?string $color, string $style): array
    {
        $style  = $this->normalizeStyle($style);
        $gender = $this->normalizeGender($gender);
        $type   = strtolower(trim($type));

        $pool = self::POOLS[$style] ?? [];

        $male    = $pool['male']    ?? [];
        $female  = $pool['female']  ?? [];
        $neutral = $pool['neutral'] ?? [];

        $candidates = match ($gender) {
            'male'   => array_merge($male, $neutral),
            'female' => array_merge($female, $neutral),
            default  => array_merge($male, $female, $neutral),
        };

        // Add type-based flavor
        if (isset(self::TYPE_FLAVOR[$type])) {
            $candidates = array_merge($candidates, self::TYPE_FLAVOR[$type]);
        }

        // Add color as a possible name
        if ($color) {
            $candidates[] = ucfirst(strtolower(trim($color)));
        }

        // Deduplicate & shuffle
        $candidates = array_values(array_unique($candidates));
        shuffle($candidates);

        // Take first 20
        $names = array_slice($candidates, 0, 20);

        // Pad from FALLBACK if fewer than 20 (guarantees count = 20)
        if (count($names) < 20) {
            $fallback = self::FALLBACK;
            shuffle($fallback);
            foreach ($fallback as $f) {
                if (! in_array($f, $names, true)) {
                    $names[] = $f;
                }
                if (count($names) === 20) break;
            }
        }

        // Final safety: trim to exactly 20
        $names = array_slice($names, 0, 20);

        return ['names' => $names];
    }

    private function normalizeStyle(string $style): string
    {
        $style = ucfirst(strtolower(trim($style)));

        return match ($style) {
            'Food-inspired', 'Food inspired', 'Foodinspired' => 'Food-inspired',
            'Royal'                                          => 'Royal',
            'Cute', 'Cool', 'Funny', 'Sinhala', 'English', 'Unique' => $style,
            default                                          => 'Cute',
        };
    }

    private function normalizeGender(string $gender): string
    {
        return in_array(strtolower(trim($gender)), ['male', 'female'], true)
            ? strtolower(trim($gender))
            : 'unknown';
    }
}