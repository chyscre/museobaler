<?php

namespace Database\Seeders;

use App\Support\PasswordPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Staff (admin login) ───────────────────────────────────────────────
        // SECURITY: never ship fixed default passwords in source (e.g. the old
        // "Admin@1234" / "Curator@1234") — anyone who reads this file, now or
        // after it's committed to version control, would know a working admin
        // login for every install that ran the seeder unattended. Instead, take
        // credentials from the environment when provided, otherwise generate a
        // random one-time password per run and print it once so it can be
        // captured and stored — never persisted anywhere but the hash.
        $adminEmail     = env('SEED_ADMIN_EMAIL', 'admin@museobaler.com');
        $adminPassword  = env('SEED_ADMIN_PASSWORD');
        $tourismEmail    = env('SEED_TOURISM_EMAIL', 'tourism@baler.gov.ph');
        $tourismPassword = env('SEED_TOURISM_PASSWORD');

        $generated = [];
        if (!$adminPassword) {
            $adminPassword = $this->generatePassword();
            $generated[$adminEmail] = $adminPassword;
        }
        if (!$tourismPassword) {
            $tourismPassword = $this->generatePassword();
            $generated[$tourismEmail] = $tourismPassword;
        }

        // Every account is a named person, not a shared post. The audit log
        // and the admission trail are only worth anything if "who did this"
        // resolves to one human — a shared "Administrator" login at the front
        // desk would make every fee collected untraceable.
        // Two accounts only: the Tourism office that oversees the museum, and
        // the museum administrator who runs it. Everyone else is created by a
        // real person through the Staff screen, so each account maps to a
        // named human and the audit trail stays meaningful.
        //
        // Both go in needing a password change. A seeded password is either
        // printed to a console or read out of an .env file, which makes it a
        // credential more than one party has seen — the same situation a
        // Tourism-issued temporary password is in, and it gets the same
        // treatment: it survives exactly one sign-in.
        DB::table('staff')->insertOrIgnore([
            ['name' => 'Tourism Head',   'email' => $tourismEmail, 'password' => Hash::make($tourismPassword), 'role' => 'TourismHead',    'status' => 1, 'must_change_password' => 1, 'password_changed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Administrator',  'email' => $adminEmail,   'password' => Hash::make($adminPassword),   'role' => 'Administrator', 'status' => 1, 'must_change_password' => 1, 'password_changed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // A default Mon-Sat shift so attendance has something to judge against
        // from day one; the Tourism office adjusts it per person afterwards.
        //
        // Museum staff only. The head of tourism works from the municipal
        // office and does not clock in, so giving her a schedule would put a
        // permanent "Absent" against her name on the attendance board.
        $museumStaff = DB::table('staff')->where('role', 'Administrator')->pluck('staff_id');

        foreach ($museumStaff as $staffId) {
            foreach (range(0, 6) as $weekday) {
                DB::table('staff_schedules')->insertOrIgnore([
                    'staff_id'      => $staffId,
                    'weekday'       => $weekday,
                    'shift_start'   => $weekday === 0 ? '00:00' : '08:00',
                    'shift_end'     => $weekday === 0 ? '00:00' : '17:00',
                    'grace_minutes' => 15,
                    'is_rest_day'   => $weekday === 0 ? 1 : 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }

        if ($generated) {
            $this->command?->warn('Generated staff credentials (shown once — save them now):');
            foreach ($generated as $email => $password) {
                $this->command?->line("  {$email} / {$password}");
            }
        }

        // ── Categories ────────────────────────────────────────────────────────
        foreach (['History', 'Culture', 'Nature', 'Science', 'Religion', 'Artifacts'] as $cat) {
            DB::table('categories')->insertOrIgnore(['name' => $cat, 'created_at' => now(), 'updated_at' => now()]);
        }

        // ── Museum Info ───────────────────────────────────────────────────────
        DB::table('museum_info')->insertOrIgnore([
            'info_id'    => 1,
            'name'       => 'Museo de Baler',
            'tagline'    => 'Baler, Aurora, Philippines',
            'story'      => 'Museo de Baler is the official municipal museum of Baler, Aurora. Inaugurated in 2002, it was established to commemorate Philippine-Spanish Friendship Day and the historic Siege of Baler. The museum preserves and showcases the rich cultural heritage, natural history, and significant historical events of Baler and Aurora Province.',
            'story2'     => 'The museum houses artifacts, dioramas, and exhibits spanning the pre-colonial era, the Spanish colonial period, the Philippine Revolution, and the modern history of Aurora Province — including the birthplace of President Manuel L. Quezon, the first President of the Philippine Commonwealth.',
            'address'    => 'Quezon St., Baler, Aurora 3200',
            'hours'      => 'Tue–Sun: 8:00 AM – 5:00 PM',
            'closed_on'  => 'Mondays & Holidays',
            'phone'      => '(042) 284-5678',
            'email'      => 'museobaler@aurora.gov.ph',
            'admission'  => 'Free for all visitors',
            // Previously hardcoded as MUSEUM_LAT/MUSEUM_LNG in
            // public/visitor/js/app.js — seeded here so a fresh install
            // keeps the same geofence behavior until staff adjust it
            // from the Museum Info page.
            'latitude'   => 15.760440549923766,
            'longitude'  => 121.56169583726937,
            'geofence_radius_m' => 150,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Museum Halls ──────────────────────────────────────────────────────
        $halls = [
            ['name' => 'Hall A — History & Religion', 'floor' => 'Ground Floor', 'description' => 'Siege of Baler & colonial era',  'icon' => 'history_edu',     'sort_order' => 1],
            ['name' => 'Hall B — Culture & Heritage', 'floor' => 'Ground Floor', 'description' => 'Aurora Province traditions',      'icon' => 'palette',         'sort_order' => 2],
            ['name' => 'Hall C — Artifacts & Crafts', 'floor' => 'Ground Floor', 'description' => 'Indigenous tools & crafts',       'icon' => 'category',        'sort_order' => 3],
            ['name' => 'Hall D — National Legacy',    'floor' => '2nd Floor',    'description' => 'Quezon & modern Aurora',           'icon' => 'account_balance', 'sort_order' => 4],
            ['name' => 'Hall E — Nature & Science',   'floor' => '2nd Floor',    'description' => 'Sierra Madre biodiversity',        'icon' => 'forest',          'sort_order' => 5],
        ];
        foreach ($halls as $hall) {
            DB::table('museum_halls')->insertOrIgnore(array_merge($hall, ['created_at' => now(), 'updated_at' => now()]));
        }
        // Exhibits below name their hall by its letter; resolve that to the row.
        $hallId = fn (string $letter) => DB::table('museum_halls')->where('name', 'like', "Hall $letter%")->value('hall_id');

        // ── Category IDs ──────────────────────────────────────────────────────
        $histId  = DB::table('categories')->where('name', 'History')->value('category_id');
        $cultId  = DB::table('categories')->where('name', 'Culture')->value('category_id');
        $natId   = DB::table('categories')->where('name', 'Nature')->value('category_id');
        $relId   = DB::table('categories')->where('name', 'Religion')->value('category_id');
        $artId   = DB::table('categories')->where('name', 'Artifacts')->value('category_id');

        // ── Exhibits ──────────────────────────────────────────────────────────
        // Their pictures and audio guides first, so a fresh install does not
        // seed rows that point at files it does not have.
        $this->copySeedMedia();

        $exhibits = [
            [
                'exhibit_id'      => 1,
                'exhibit_code'    => 'EXH-001',
                'name'            => 'Siege of Baler',
                'description'     => 'From July 1, 1898 to June 2, 1899, a garrison of 54 Spanish soldiers under Captain Enrique de las Morenas fortified themselves inside the San Luis Obispo de Tolosa Church in Baler. Filipino revolutionary forces laid siege for 337 days — one of the longest last stands of the Spanish colonial era. Remarkably, the soldiers held out even after Spain had already ceded the Philippines to the United States through the Treaty of Paris on December 10, 1898. The siege ended when Lieutenant Saturnino Martin Cerezo finally surrendered, unaware the war had long been over. This exhibit features a diorama of the siege, period weapons, and historical documents.',
                'fun_facts'       => "The siege lasted 337 days — the longest holdout of the Spanish colonial period in the Philippines.\nThe Spanish soldiers were unaware that Spain had already surrendered the Philippines to the US months earlier.\nCaptain Enrique de las Morenas died of illness during the siege; Lieutenant Martin Cerezo took command.\nOnly 33 of the original 54 soldiers survived to surrender on June 2, 1899.\nThe event is commemorated annually on Philippine-Spanish Friendship Day.",
                'category_id'     => $histId,
                'hall_id'         => $hallId('A'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 1,
                'image'           => 'Siege_of_Baler_Diorama_1776872245.jpeg',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 2,
                'exhibit_code'    => 'EXH-002',
                'name'            => 'Casiguran Agta: People of the Forest',
                'description'     => 'The Casiguran Agta (also known as Dumagat) are among the earliest inhabitants of the Philippines — a Negrito people who have lived along the Sierra Madre and the Pacific coast of Aurora for thousands of years. Traditionally nomadic hunter-gatherers, they subsisted on forest game, river fish, and coastal seafood. Their intimate knowledge of the Sierra Madre\'s ecosystems — its plants, animals, rivers, and seasons — reflects generations of careful observation and dependence on the natural world. This exhibit highlights their tools, traditions, and the cultural pressures they face today.',
                'fun_facts'       => "The Agta are considered among the earliest inhabitants of the Philippine archipelago.\nThey are one of approximately 25 Negrito ethnolinguistic groups found across the Philippines.\nCasiguran Dumagat Agta is a distinct Northeastern Luzon language still spoken today.\nIn the past, the Agta lived freely along the coasts; logging and homesteaders later pushed many into the mountains.\nThe Agta traditionally practiced a barter system, trading forest products with lowland farmers.",
                'category_id'     => $cultId,
                'hall_id'         => $hallId('B'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 2,
                'image'           => 'Casiguran_Agta__People_of_the_Forest_1776872330.jpg',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 3,
                'exhibit_code'    => 'EXH-003',
                'name'            => 'Aurora Province Heritage',
                'description'     => 'Aurora Province, carved out of Quezon Province in 1979, was named after Aurora Aragon — the beloved wife of President Manuel L. Quezon. This exhibit celebrates the province\'s unique blend of indigenous, Spanish colonial, and modern Filipino heritage. From the early Franciscan missions established in Baler and Casiguran in 1609 to the traditions of its diverse peoples, Aurora\'s heritage reflects centuries of resilience, faith, and cultural pride. Featured are traditional garments, woven crafts, ceremonial objects, and historical photographs documenting life in Aurora across the centuries.',
                'fun_facts'       => "Aurora Province was created by Presidential Decree No. 1649 on August 13, 1979.\nIt was named after Aurora Aragon Quezon, who was assassinated in an ambush in 1949.\nThe Franciscans established the first missions in Baler and Casiguran as early as 1609.\nAurora is the only province in Central Luzon without any chartered city.\nThe province borders six other provinces: Quezon, Bulacan, Nueva Ecija, Nueva Vizcaya, Quirino, and Isabela.",
                'category_id'     => $cultId,
                'hall_id'         => $hallId('B'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 3,
                'image'           => 'Aurora_Province_Heritage_1776873743.jpg',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 4,
                'exhibit_code'    => 'EXH-004',
                'name'            => 'Quezon Family and Baler\'s National Legacy',
                'description'     => 'Baler, Aurora is the birthplace of Manuel Luis Quezon y Molina — born on August 19, 1878 — who rose from this quiet coastal town to become the first President of the Philippine Commonwealth (1935–1944). Educated at San Juan de Letran and the University of Santo Tomás, Quezon fought in the Philippine Revolution, entered politics, and became one of the nation\'s most influential statesmen. He proclaimed Filipino as the national language in 1937. He died in exile on August 1, 1944, in Saranac Lake, New York. This exhibit traces the Quezon family\'s roots in Baler and their enduring legacy on Philippine nationhood.',
                'fun_facts'       => "Manuel L. Quezon was born on August 19, 1878, right here in Baler.\nHe was the first President of the Philippine Commonwealth, serving from 1935 until his death in 1944.\nQuezon proclaimed Filipino (based on Tagalog) as the national language in 1937.\nAurora Province was named after his wife, Aurora Aragon Quezon.\nQuezon City, the most populous city in the Philippines, is also named in his honor.",
                'category_id'     => $histId,
                'hall_id'         => $hallId('D'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 4,
                'image'           => 'Quezon_Family_and_Baler_s_National_Legacy_1776873808.jpg',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 5,
                'exhibit_code'    => 'EXH-005',
                'name'            => 'Baler Church and the Last of the Philippines',
                'description'     => 'The San Luis Obispo de Tolosa Parish Church is one of the most historically significant structures in Baler. Originally built by Franciscan missionaries who founded the settlement in 1609, the church has witnessed centuries of faith, colonial rule, natural disaster, and revolution. It was within these walls that Spanish troops made their legendary last stand during the 337-day Siege of Baler. A great storm in 1735 devastated the old settlement at Barrio Sabang, and the survivors rebuilt their community around the church. This exhibit traces the church\'s role as the spiritual and historical heart of Baler.',
                'fun_facts'       => "The Franciscans founded Baler as a settlement in 1609, with the church as its spiritual center.\nA catastrophic storm on December 27, 1735 swept away the old settlement at Barrio Sabang.\nThe church served as the fortress during the 337-day Siege of Baler in 1898–1899.\nThe church is dedicated to Saint Louis of Toulouse (San Luis Obispo de Tolosa).\nIt remains an active parish church and a National Cultural Treasure.",
                'category_id'     => $relId,
                'hall_id'         => $hallId('A'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 5,
                'image'           => 'Baler_Church_and_the_Last_of_the_Philippines_1776873720.gif',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 6,
                'exhibit_code'    => 'EXH-006',
                'name'            => 'Baler Bay and the Pacific Coast',
                'description'     => 'Baler Bay sits along the Pacific coastline of Aurora Province, sheltered by the foothills of the Sierra Madre. Long before it became famous as a surfing destination, Baler Bay was the lifeblood of the community — a source of food, trade, and livelihood for generations of fisherfolk. The bay was also the site of dramatic historical events: Spanish supply ships attempted to relieve the besieged garrison during the Siege of Baler through these very waters. Today, the bay is internationally known for its powerful surf breaks, drawing visitors from around the world while its fishing traditions endure.',
                'fun_facts'       => "Baler Bay gained international fame after the 1979 film Apocalypse Now was filmed nearby, introducing surfing to the area.\nThe bay faces the Philippine Sea, an arm of the western Pacific Ocean.\nLocal fisherfolk have used traditional methods like the pukot (fish net) for centuries.\nBaler was virtually inaccessible by road until the 1970s — most goods and people arrived by sea.\nThe town's name may derive from the old Tagalog word for a type of fishing vessel.",
                'category_id'     => $cultId,
                'hall_id'         => $hallId('C'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 6,
                'image'           => 'Baler_Bay_and_the_Pacific_Coast_1776873790.jpg',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 7,
                'exhibit_code'    => 'EXH-007',
                'name'            => 'Traditional Fishing and Coastal Life',
                'description'     => 'For centuries, the coastal communities of Baler and Aurora Province have depended on the sea for survival. This exhibit documents the traditional fishing practices, vessels, and tools used by the fisherfolk of Aurora — from hand-woven nets and bamboo fish traps to the distinct outrigger bancas (canoes) built for the Pacific swells. The fishing community\'s calendar was shaped by the seasons: the habagat (southwest monsoon) brought rough seas and rest, while the amihan (northeast monsoon) opened the waters for bountiful harvests. Displayed here are original tools, models of traditional vessels, and photographs of coastal life across generations.',
                'fun_facts'       => "The traditional outrigger banca is still the primary fishing vessel used by Aurora fisherfolk today.\nThe habagat season (June–October) brings heavy surf that historically shut down fishing in Baler Bay.\nFish traps woven from bamboo called bubo have been used in Aurora for hundreds of years.\nLocal communities once used the tromba marina warning system to alert each other of approaching storms.\nThe 1735 great storm that destroyed old Baler originated in the Pacific and struck without warning.",
                'category_id'     => $cultId,
                'hall_id'         => $hallId('C'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 7,
                'image'           => 'Traditional_Fishing_and_Coastal_Life_1776873840.webp',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'exhibit_id'      => 8,
                'exhibit_code'    => 'EXH-008',
                'name'            => 'Sierra Madre: Lungs of Luzon',
                'description'     => 'The Sierra Madre is the longest mountain range in the Philippines, stretching over 540 kilometers from the northernmost tip of Luzon down to Quezon Province. It forms the natural eastern wall of Aurora Province, sheltering Baler from the open Pacific while nurturing one of the most biodiverse ecosystems in Asia. Home to over 700 bird species — many endemic to the Philippines — as well as endangered mammals like the Philippine Eagle and the cloud rat, the Sierra Madre is the last great wilderness of Luzon. This exhibit explores its ecology, the communities that call it home, and the urgent conservation challenges it faces.',
                'fun_facts'       => "The Sierra Madre stretches over 540 km — making it the longest mountain range in the Philippines.\nIt is home to over 700 bird species, including the critically endangered Philippine Eagle.\nThe Sierra Madre is considered one of the top biodiversity hotspots in the entire world.\nThe forest canopy of the Sierra Madre acts as a natural barrier protecting central Luzon from Pacific typhoons.\nThe Agta Dumagat people have lived within the Sierra Madre for thousands of years.",
                'category_id'     => $natId,
                'hall_id'         => $hallId('E'),
                'authors'         => 'Museo de Baler Curatorial Team',
                'languages'       => 'Filipino,English',
                'storyline_order' => 8,
                'image'           => 'Sierra_Madre__Lungs_of_Luzon_1776873825.jpg',
                'status'          => 1,
                'date_published'  => '2024-06-01',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ];

        foreach ($exhibits as $exhibit) {
            DB::table('exhibits')->insertOrIgnore($exhibit);
        }

        // ── Exhibit Translations ──────────────────────────────────────────────
        $translations = [
            // Exhibit 1 — Siege of Baler
            ['exhibit_id' => 1, 'language_code' => 'en', 'language_label' => 'English',
             'title' => 'Siege of Baler',
             'description' => 'From July 1, 1898 to June 2, 1899, a garrison of 54 Spanish soldiers fortified themselves inside the San Luis Obispo de Tolosa Church in Baler. Filipino revolutionary forces laid siege for 337 days. The soldiers held out even after Spain had ceded the Philippines to the United States through the Treaty of Paris on December 10, 1898.',
             'fun_facts' => "The siege lasted 337 days — the longest holdout of the Spanish colonial period in the Philippines.\nOnly 33 of the original 54 soldiers survived to surrender on June 2, 1899.\nThe soldiers were unaware that Spain had already surrendered the Philippines to the US months earlier.",
             'audio_file' => 'exhibit_1_en_1776872045.mp3', 'created_at' => now(), 'updated_at' => now()],

            ['exhibit_id' => 1, 'language_code' => 'fil', 'language_label' => 'Filipino',
             'title' => 'Pagkubkob ng Baler',
             'description' => 'Mula Hulyo 1, 1898 hanggang Hunyo 2, 1899, ang isang pangkat ng 54 na sundalong Espanyol ay nagkutang-loob sa loob ng Simbahan ng San Luis Obispo de Tolosa sa Baler. Nagkubkob ang mga rebolusyonaryong Pilipino sa loob ng 337 araw. Patuloy na lumaban ang mga sundalo kahit matagal nang ibinigay ng Espanya ang Pilipinas sa Estados Unidos sa pamamagitan ng Kasunduan ng Paris noong Disyembre 10, 1898.',
             'fun_facts' => "Ang pagkubkob ay tumagal ng 337 araw — ang pinakamatagal na pagtutol sa panahon ng kolonyalismong Espanyol sa Pilipinas.\n33 lamang sa orihinal na 54 na sundalo ang nakaligtas hanggang sa pagsuko noong Hunyo 2, 1899.\nHindi alam ng mga sundalo na matagal na palang sumusuko ang Espanya sa Estados Unidos.",
             'audio_file' => 'exhibit_1_fil_1776872082.mp3', 'created_at' => now(), 'updated_at' => now()],

            // Exhibit 2 — Casiguran Agta
            ['exhibit_id' => 2, 'language_code' => 'en', 'language_label' => 'English',
             'title' => 'Casiguran Agta: People of the Forest',
             'description' => 'The Casiguran Agta (Dumagat) are among the earliest inhabitants of the Philippines — a Negrito people who have lived along the Sierra Madre and the Pacific coast of Aurora for thousands of years. Traditionally nomadic hunter-gatherers, they subsisted on forest game, river fish, and coastal seafood.',
             'fun_facts' => "The Agta are considered among the earliest inhabitants of the Philippine archipelago.\nCasiguran Dumagat Agta is a distinct Northeastern Luzon language still spoken today.\nThe Agta traditionally practiced a barter system, trading forest products with lowland farmers.",
             'audio_file' => 'exhibit_2_en_1776872158.mp3', 'created_at' => now(), 'updated_at' => now()],

            ['exhibit_id' => 2, 'language_code' => 'fil', 'language_label' => 'Filipino',
             'title' => 'Casiguran Agta: Mga Tao ng Kagubatan',
             'description' => 'Ang mga Casiguran Agta (Dumagat) ay kabilang sa mga pinakasinaunang naninirahan sa Pilipinas — isang grupong Negrito na naninirahan sa kahabaan ng Sierra Madre at baybayin ng Pasipiko ng Aurora sa loob ng libu-libong taon. Tradisyonal na mga nomadic hunter-gatherer, sila ay nabuhay sa mga hayop sa kagubatan, isda sa ilog, at pagkain sa baybayin.',
             'fun_facts' => "Ang mga Agta ay itinuturing na kabilang sa mga pinakasinaunang naninirahan sa arkipelago ng Pilipinas.\nAng wikang Casiguran Dumagat Agta ay isang natatanging wikang Northeastern Luzon na ginagamit pa rin ngayon.\nAng mga Agta ay tradisyonal na nagsasagawa ng sistema ng palitan, nagpapalitan ng mga produkto sa kagubatan sa mga magsasaka sa lambak.",
             'audio_file' => 'exhibit_2_fil_1776872179.mp3', 'created_at' => now(), 'updated_at' => now()],

            // Exhibit 4 — Quezon Family
            ['exhibit_id' => 4, 'language_code' => 'en', 'language_label' => 'English',
             'title' => 'Quezon Family and Baler\'s National Legacy',
             'description' => 'Baler is the birthplace of Manuel Luis Quezon y Molina — born August 19, 1878 — who became the first President of the Philippine Commonwealth (1935–1944). He proclaimed Filipino as the national language in 1937 and led the government in exile during World War II until his death on August 1, 1944.',
             'fun_facts' => "Manuel L. Quezon was born on August 19, 1878, right here in Baler.\nHe was the first President of the Philippine Commonwealth, serving from 1935 until his death in 1944.\nQuezon proclaimed Filipino as the national language in 1937.\nQuezon City, the most populous city in the Philippines, is named in his honor.",
             'audio_file' => 'exhibit_4_en_1776787738.mp3', 'created_at' => now(), 'updated_at' => now()],

            ['exhibit_id' => 4, 'language_code' => 'fil', 'language_label' => 'Filipino',
             'title' => 'Pamilyang Quezon at ang Pamana ng Baler sa Bansa',
             'description' => 'Ang Baler ang tinubuang-lupa ni Manuel Luis Quezon y Molina — ipinanganak noong Agosto 19, 1878 — na naging unang Pangulo ng Komonwelt ng Pilipinas (1935–1944). Iprinoklamar niya ang Filipino bilang pambansang wika noong 1937 at pinamunuan ang pamahalaan sa exile sa panahon ng Ikalawang Digmaang Pandaigdig hanggang sa kanyang kamatayan noong Agosto 1, 1944.',
             'fun_facts' => "Si Manuel L. Quezon ay ipinanganak noong Agosto 19, 1878, dito sa Baler.\nSiya ang unang Pangulo ng Komonwelt ng Pilipinas, naglingkod mula 1935 hanggang sa kanyang kamatayan noong 1944.\nIprinoklamar niya ang Filipino bilang pambansang wika noong 1937.\nAng Quezon City, ang pinakamasiglang lungsod sa Pilipinas, ay pinangalanan sa kanyang karangalan.",
             'audio_file' => 'exhibit_4_fil_1776790694.mp3', 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($translations as $t) {
            DB::table('exhibit_translations')->insertOrIgnore($t);
        }

        // ── Extra gallery images ───────────────────────────────────────────────
        $gallery = [
            ['exhibit_id' => 1, 'filename' => 'Siege_of_Baler_Diorama_1776873587.jpeg', 'caption' => 'Siege of Baler diorama — side view', 'sort_order' => 1],
            ['exhibit_id' => 2, 'filename' => 'Casiguran_Agta__People_of_the_Forest_1776873765.jpg', 'caption' => 'Agta community along the Sierra Madre', 'sort_order' => 1],
            ['exhibit_id' => 5, 'filename' => 'churchofbalernow_17768772510.jpg', 'caption' => 'San Luis Obispo de Tolosa Church today', 'sort_order' => 1],
        ];

        foreach ($gallery as $img) {
            DB::table('exhibit_images')->insertOrIgnore(array_merge($img, ['created_at' => now(), 'updated_at' => now()]));
        }

        // ── Notifications ─────────────────────────────────────────────────────
        $notifications = [
            ['title' => 'Welcome to Museo de Baler!',     'body' => 'Explore the rich history and culture of Baler, Aurora. Scan QR codes on exhibits to learn more.', 'type' => 'info',  'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Free Admission for All Visitors', 'body' => 'Museo de Baler is free and open to everyone. Open Tuesday to Sunday, 8:00 AM – 5:00 PM.',          'type' => 'promo', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($notifications as $notif) {
            DB::table('notifications')->insertOrIgnore($notif);
        }
    }

    /**
     * The bootstrap accounts' one-time passwords.
     *
     * Deferred to PasswordPolicy so there is a single generator: when the
     * rules move, the seeder cannot quietly go on producing passwords that
     * no longer satisfy them.
     */
    private function generatePassword(): string
    {
        return PasswordPolicy::generateTemporary();
    }

    /**
     * The seeded exhibits' pictures and audio guides.
     *
     * They travel with the code, in database/seeders/media, so a fresh
     * install has them. Everything staff upload afterwards lands in
     * public/images/exhibits and public/audio, which are not in git - on a
     * server they are a shared directory that outlives releases. The copy
     * only fills gaps: a file already there, seeded or uploaded, is left alone.
     */
    private function copySeedMedia(): void
    {
        foreach (['images' => 'images/exhibits', 'audio' => 'audio'] as $from => $to) {
            $source = database_path("seeders/media/{$from}");
            $target = public_path($to);

            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                $this->command?->warn("Could not create {$target}; seeded exhibits will have no {$from}.");
                continue;
            }

            foreach (glob("{$source}/*") ?: [] as $file) {
                $dest = $target . '/' . basename($file);
                if (!is_file($dest)) {
                    copy($file, $dest);
                }
            }
        }
    }
}
