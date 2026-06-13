<?php
declare(strict_types=1);

/**
 * Wellness chat: question-aware replies, crisis escalation (PH), EN/TL.
 */

function wellness_normalize_for_match(string $text): string
{
    $t = mb_strtolower(trim($text), 'UTF-8');
    return preg_replace('/\s+/u', ' ', $t) ?: '';
}

/** @return 'en'|'tl' */
function wellness_resolve_language(?string $clientHint, string $messageNorm): string
{
    $hint = $clientHint !== null ? mb_strtolower(trim($clientHint), 'UTF-8') : '';
    if (in_array($hint, ['tl', 'fil', 'tagalog'], true)) {
        return 'tl';
    }
    if (in_array($hint, ['en', 'english'], true)) {
        return 'en';
    }

    $tlPatterns = [
        'ako ', ' ako', 'hindi ako', 'hindi ko', 'saan ', ' bakit ', 'kasi ', 'dahil ',
        'nababalisa', 'nababahala', 'nalulungkot', 'pakiramdam', 'pagod', 'wala akong',
        'gusto kong', 'naman ', 'po ', 'opo', 'ayoko ', 'tulong', 'nakakatakot',
        'nakaka-stress', 'sama ng loob', 'kabado', 'kinakabahan', 'mag-isa', 'nag-iisa',
        'paano ', 'ano ang', 'bakit ',
    ];
    $tlLex = ['ako', 'hindi', 'ba', 'ang', 'ng', 'kung', 'pero', 'ito', 'yung', 'naman', 'lang'];

    $score = 0;
    foreach ($tlPatterns as $p) {
        if (mb_strpos($messageNorm, $p, 0, 'UTF-8') !== false) {
            $score += 3;
        }
    }
    foreach (preg_split('/[^\p{L}\p{N}_]+/u', $messageNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (in_array($w, $tlLex, true)) {
            $score += 1;
        }
    }

    return $score >= 4 ? 'tl' : 'en';
}

function wellness_is_crisis_message(string $messageNorm): bool
{
    if ($messageNorm === '') {
        return false;
    }

    $needles = [
        'suicidal', 'suicide', 'kill myself', 'end my life', 'end it all',
        'want to die', 'dont want to live', 'don\'t want to live',
        'better off dead', 'self harm', 'selfharm', 'self-harm', 'hurt myself',
        'cant go on', 'cannot go on', 'cant take it anymore', 'plan to kill',
        'magpakamatay', 'magsuicide', 'patay na lang', 'mamatay na lang',
        'gusto ko nang mamatay', 'gusto kong mamatay', 'wakasan ang buhay',
        'tapusin ang buhay', 'ayoko na mabuhay', 'ayoko nang mabuhay',
        'wala akong dahilan para mabuhay',
    ];

    foreach ($needles as $n) {
        if (mb_strpos($messageNorm, $n, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

function wellness_message_is_question(string $norm): bool
{
    if (str_contains($norm, '?')) {
        return true;
    }
    $starters = [
        'how ', 'what ', 'why ', 'when ', 'where ', 'can i ', 'should i ', 'is it ',
        'paano ', 'bakit ', 'ano ', 'saan ', 'kailan ', 'puwede ba ', 'pwede ba ',
        'may paraan ba', 'normal ba ',
    ];
    foreach ($starters as $s) {
        if (str_starts_with($norm, $s)) {
            return true;
        }
    }

    return false;
}

/**
 * Score scenario keys from message content (higher = stronger match).
 *
 * @return array<string, int>
 */
function wellness_score_scenarios(string $norm): array
{
    $rules = [
        'exam_school' => [
            'exam', 'quiz', 'midterm', 'finals', 'thesis', 'assignment', 'grades', 'gpa',
            'class', 'prof', 'school', 'aral', 'pagsusulit', 'quiz', 'projekto', 'report',
            'deadline', 'pasahan',
        ],
        'sleep' => [
            'cant sleep', 'can\'t sleep', 'cannot sleep', 'insomnia', 'sleepless', 'no sleep',
            'hard to sleep', 'trouble sleeping', 'hindi makatulog', 'di makatulog', 'puyat',
            'gising', '3am', '4am', 'tulog', ' sleep ', 'sleeping',
        ],
        'loneliness' => [
            'lonely', 'loneliness', 'alone', 'no friends', 'walang kaibigan', 'mag-isa',
            'nag-iisa', 'walang kasama', 'isolated', 'nobody cares', 'wala akong kaibigan',
        ],
        'family' => [
            'parents', 'parent', 'mom', 'dad', 'family', 'magulang', 'ina', 'ama', 'nanay',
            'tatay', 'pamilya', 'pressure sa bahay', 'hindi ako maintindihan',
        ],
        'relationship' => [
            'breakup', 'broke up', 'ex ', 'boyfriend', 'girlfriend', 'partner', 'relationship',
            'iniwan ako', 'hiwalay', 'kasintahan', 'jowa', 'heartbreak', 'broken heart',
        ],
        'anxiety' => [
            'anxiety', 'anxious', 'panic', 'panicking', 'worried', 'worry', 'nervous',
            'nababahala', 'kabado', 'kinakabahan', 'overthink', 'takot na takot',
        ],
        'low_mood' => [
            'sad', 'depressed', 'depression', 'hopeless', 'empty', 'down', 'miserable',
            'lungkot', 'malungkot', 'nalulungkot', 'hindi masaya', 'walang gana', 'bigat',
        ],
        'stress_burnout' => [
            'stress', 'stressed', 'burnout', 'burned out', 'overwhelm', 'overloaded',
            'pagod na pagod', 'sobrang pagod', 'nakakastress', 'pressure', 'overwhelmed',
        ],
        'help_seeking' => [
            'counselor', 'therapist', 'psychologist', 'guidance', 'professional help',
            'konsultasyon', 'magpa-check', 'saan ako lalapit', 'saan puwedeng lumapit',
            'need help', 'help me', 'tulong po', 'tulong sa',
        ],
        'coping_how' => [
            'how do i cope', 'how can i cope', 'how to cope', 'what should i do',
            'paano ko haharapin', 'paano ko ito haharapin', 'ano ang gagawin ko',
            'tips', 'advice', 'suggestions',
        ],
        'why_feel' => [
            'why do i feel', 'why am i', 'bakit ako', 'bakit ganito', 'bakit parang',
            'normal ba na', 'is it normal',
        ],
        'social_fear' => [
            'social anxiety', 'presentation', 'public speaking', 'recit', 'recitation',
            'nahihiya', 'nahihiya ako', 'takot magsalita', 'group work',
        ],
    ];

    $scores = [];
    foreach ($rules as $key => $patterns) {
        $s = 0;
        foreach ($patterns as $p) {
            if (mb_strpos($norm, $p, 0, 'UTF-8') !== false) {
                $s += mb_strlen($p, 'UTF-8') >= 8 ? 3 : 2;
            }
        }
        if ($s > 0) {
            $scores[$key] = $s;
        }
    }

    if (wellness_message_is_question($norm)) {
        if (preg_match('/\b(how|paano)\b/u', $norm)) {
            $scores['coping_how'] = ($scores['coping_how'] ?? 0) + 2;
        }
        $domainScore = 0;
        foreach ($scores as $k => $v) {
            if (!in_array($k, ['coping_how', 'why_feel', 'help_seeking'], true)) {
                $domainScore = max($domainScore, $v);
            }
        }
        if (preg_match('/\b(why|bakit)\b/u', $norm) && $domainScore < 3) {
            $scores['why_feel'] = ($scores['why_feel'] ?? 0) + 2;
        }
    }

    arsort($scores);

    return $scores;
}

/**
 * @return list<string> topic tags for API metadata
 */
function wellness_topics_from_scenarios(array $scores): array
{
    $map = [
        'exam_school' => 'stress',
        'sleep' => 'stress',
        'loneliness' => 'loneliness',
        'family' => 'stress',
        'relationship' => 'low_mood',
        'anxiety' => 'anxiety',
        'low_mood' => 'low_mood',
        'stress_burnout' => 'stress',
        'help_seeking' => 'support',
        'coping_how' => 'support',
        'why_feel' => 'support',
        'social_fear' => 'anxiety',
    ];

    $topics = [];
    foreach (array_keys($scores) as $sc) {
        if (isset($map[$sc])) {
            $topics[] = $map[$sc];
        }
    }

    return array_values(array_unique($topics));
}

/**
 * @return array{intent: string, scenarios: list<string>}
 */
function wellness_classify(string $norm): array
{
    $scores = wellness_score_scenarios($norm);
    $scenarios = array_keys($scores);
    $primary = $scenarios[0] ?? 'general_support';
    $intent = $primary;
    if (wellness_message_is_question($norm)) {
        if (($scores['coping_how'] ?? 0) > 0 || ($scores['help_seeking'] ?? 0) > 0) {
            $intent = 'question_coping';
        } elseif (($scores['why_feel'] ?? 0) > 0) {
            $intent = 'question_explainer';
        } else {
            $intent = 'question_' . $primary;
        }
    }

    return ['intent' => $intent, 'scenarios' => $scenarios !== [] ? $scenarios : ['general_support']];
}

function wellness_snippet_safe(string $message, int $max = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
    $s = preg_replace('/["\x00-\x1F]/u', ' ', $s) ?? '';
    if (mb_strlen($s, 'UTF-8') > $max) {
        return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
    }

    return $s;
}

/** @return array{how: bool, why: bool} */
function wellness_question_flavor(string $norm): array
{
    return [
        'how' => (bool) preg_match('/\b(how|paano|ano ang gagawin|what should i)\b/u', $norm),
        'why' => (bool) preg_match('/\b(why|bakit|normal ba)\b/u', $norm),
    ];
}

/**
 * Natural, conversational reply — no quote-back, no "Direct answer" headers.
 *
 * @param list<string>        $scenarios
 * @param array<string, int> $scores
 */
function wellness_compose_natural_reply(string $primary, array $scenarios, array $scores, string $lang, string $norm): string
{
    $flavor = wellness_question_flavor($norm);
    $how = $flavor['how'];
    $why = $flavor['why'];

    // If generic "how to cope" but a domain scenario is stronger, answer the domain
    if ($primary === 'coping_how' && isset($scenarios[1])) {
        $second = $scenarios[1];
        if (($scores[$second] ?? 0) >= ($scores['coping_how'] ?? 0)) {
            $primary = $second;
        }
    }

    $templates = wellness_natural_templates($lang, $how, $why);
    $body = $templates[$primary] ?? $templates['general_support'];

    return trim($body);
}

/**
 * @return array<string, string>
 */
function wellness_natural_templates(string $lang, bool $how, bool $why): array
{
    if ($lang === 'tl') {
        return [
            'exam_school' => $how || $why
                ? "Nakakapagod talaga kapag exam ang nakabantay—parang lahat ng enerhiya mo napupunta sa takot na mabagsak, hindi lang sa pag-aaral.\n\n"
                    . "Subukan ito ngayong gabi at bukas:\n"
                    . "- **Isang bagay lang muna:** 25 minutong review, 5 minutong pahinga—huwag buong syllabus sa isang upuan.\n"
                    . "- **Bago pumasok sa exam room:** box breathing (hinga 4, hold 4, labas 4, hold 4) ng isang minuto.\n"
                    . "- **Sa loob ng exam:** \"good enough\" answers muna—may partial credit pa rin.\n\n"
                    . "Kung halos araw-araw ganito at hindi ka na makatulog o kumain nang maayos ng **dalawang linggo+**, mas okay ang campus guidance—hindi ako makakapag-diagnose, pero sila makakapagbigay ng plano para sa iyo."
                : "Mabigat ang pressure ng school, lalo na kapag sunod-sunod ang requirements—hindi ibig sabihin hindi ka kayang mag-aral, overload ka lang.\n\n"
                    . "Ngayong linggo: piliin **isang subject** na uunahin, mag-message sa isang kaklase o TA kung saan ka stuck, at protektahan ang tulog bago ang huling gabing cram. Kung gusto mo ng mas specific na tips, sabihin kung exam ba, project, o grades ang pinaka-nakakatakot.",

            'sleep' => $why
                ? "Madalas hindi lang \"tamad mag-sleep\"—naka-on pa ang utak mo sa mga dapat gawin bukas, o may stress na hindi pa nalalabasan.\n\n"
                    . "Pwede ring kulang ng routine, sobrang screen bago matulog, o caffeine late in the day. Subukan 3–5 gabi: **parehong oras ng gising**, isulat sa papel ang 3 priority bukas, at iwas screen 45–60 min bago matulog.\n\n"
                    . "Kung tuloy-tuloy na ito at hirap ka nang function sa araw, nurse o guidance sa campus—hindi disorder agad, pero kailangan ng tao na makakasama mag-ayos."
                : "Pagod ka na pero gising pa ang isip—nakakaiyak at nakakapagod iyon.\n\n"
                    . "1. **Gising** sa parehong oras araw-araw (kahit kulang tulog).\n"
                    . "2. **Screen off** 45–60 min bago matulog; ilipat worries sa papel.\n"
                    . "3. **Walang mabigat na caffeine** pag hapon na.\n\n"
                    . "Kung 2 linggo nang ganito at sabog ang araw mo, guidance counselor—mas malaki ang tulong kaysa mag-isang laban.",

            'loneliness' => "Masakit ang pakiramdam na wala kang kasama sa campus—maraming estudyante ang nag-iisip na sila lang ang ganito, pero hindi totoo.\n\n"
                . "Hindi kailangan maging popular. Subukan:\n"
                . "- **Isang mensahe** sa kaklase tungkol sa assignment (mababang pressure).\n"
                . "- **Isang org o group** na pupuntahan mo ng isang beses lang muna.\n"
                . "- **Isang tao** na i-text mo linggo-linggo, kahit short lang.\n\n"
                . "Kung gusto mo, sabihin kung mas hirap ka sa bagong environment, breakup, o hindi ka iniimbita—iba-iba ang angkop na hakbang.",

            'family' => "Mabigat kapag ang pamilya ang source ng pressure—minsan mahal ka nila pero hindi nila alam kung paano suportahan ang load mo ngayon.\n\n"
                . "Pwede mong sabihin nang kalmado: *\"Naririnig ko kayo; kailangan ko mag-focus sa [subject/week] muna.\"* Huminto kung mainit ang usapan; bumalik kapag kalmado na.\n\n"
                . "Kung **hindi ligtas** ang bahay o may pang-aabuso, kailangan ng tao/propesyunal agad—iba iyon sa normal na stress. Campus guidance makakatulong mag-plan.",

            'relationship' => "Valid ang sakit sa relasyon o breakup—hindi \"OA\" ang nararamdaman mo; parang mini-grief iyan.\n\n"
                . "Ngayon: huwag pilitin ang \"okay na ako\" agad; kumain at matulog kahit basic lang; bawasan ang stalking sa social kung masakit. May isang taong mapagkakatiwalaan na pwede mong kausapin.\n\n"
                . "Kung ilang araw ka nang hindi kumakain, natutulog, o hindi makapunta sa klase, guidance counselor—relationship pain pwedeng magpatagal ng low mood.",

            'anxiety' => $how
                ? "Kapag sobrang kaba, ang katawan mo ay naghahanda para sa panganib—kahit exam o recit lang ang nangyayari.\n\n"
                    . "**Ngayon mismo:**\n"
                    . "- 5-4-3-2-1 grounding (5 nakikita, 4 nahahawakan…)\n"
                    . "- Box breathing: 4 in, 4 hold, 4 out, 4 hold\n"
                    . "- I-schedule ang worry ng 15 min **mamaya**, hindi buong araw\n\n"
                    . "Kung halos araw-araw panic at hirap huminga, sabihin sa counselor—may skills silang ituturo, hindi ako magbibigay ng diagnosis."
                : "Nakakapagod ang laging naka-alert—hindi ka mahina, overloaded lang ang nervous system mo.\n\n"
                    . "Subukan grounding o box breathing bago klase; bawasan caffeine; isang maliliit na task lang sa isang oras. Kung tumatagal ng linggo at sabog ang routine mo, campus guidance.",

            'low_mood' => $why
                ? "Maraming dahilan kung bakit mabigat ang araw—kulang tulog, stress sa school, kalungkutan, away, o sunod-sunod na disappointments. **Hindi** ibig sabihin may \"sakit\" ka agad; ibig sabihin overloaded ka.\n\n"
                    . "Ngayon: **isang maliit na gawain** (ligo, lakad, isang pagkain). Iwas malaking desisyon habang pinakamababa ang mood.\n\n"
                    . "Kung 2 linggo+ na halos araw-araw ganito, propesyunal—common ang paghingi ng tulong at may mga option sa campus."
                : "Malungkot at walang gana—tunay iyan, at hindi ka nag-iisa dito.\n\n"
                    . "Ngayong araw: isang maliit na win lang (pagkain, 10-min walk, isang message sa taong safe). Bukas: ulitin. Kung hindi gumagalaw ang mood ng ilang linggo, guidance counselor—hindi ako magdi-diagnose, pero sila makakasama.",

            'stress_burnout' => "Pagod na pagod ka—burnout ang tawag ng marami, pero ibig sabihin lang nun sobra kang nagbigay nang kulang ang pahinga.\n\n"
                . "Ngayong linggo:\n"
                . "- **Alisin o ipagpaliban** ang isang commitment na hindi critical.\n"
                . "- **Protektahan** ang isang oras ng rest tulad ng klase.\n"
                . "- **Huwag** magdagdag ng bagong responsibility hanggang may konting gana ka na.\n\n"
                . "Recovery ay productive, hindi tamad.",

            'help_seeking' => "Magandang hakbang na naghahanap ka kung saan lalapit.\n\n"
                . "**Sa campus:** guidance / counseling office o student affairs—sila ang unang linya para sa talk therapy referrals at student support.\n\n"
                . "**Sa Pilipinas (urgent emotional crisis):** NCMH **1553**, o mobile lines sa crisis response ng app na ito.\n\n"
                . "Ako ay chat companion lang—hindi ako makakapag-intervene sa emergency.",

            'coping_how' => "Narito ang simpleng plan na gumagana sa maraming estudyante:\n\n"
                . "1. **Isulat** ang problema sa isang pangungusap.\n"
                . "2. **Gawin** ang isang aksyon na kaya sa 30 minuto.\n"
                . "3. **Sabihin** sa isang tao (kahit chat lang).\n"
                . "4. **Gabi:** ano ang 5% na nakatulong? Ulitin bukas.\n\n"
                . "Sabihin kung school, tulog, tao, o mood ang pinaka-mabigat para mas iangkop ko ang susunod na sagot.",

            'why_feel' => "Valid ang tanong na *bakit* ganito—hindi ka nag-iisa sa pagtataka.\n\n"
                . "Karaniwang contributors: **stress**, **kulang tulog**, **exam pressure**, **kalungkutan**, o **away** sa pamilya/relasyon. Hindi ibig sabihin may disorder ka—pattern lang ng buhay mo ngayon.\n\n"
                . "Kung matindi, araw-araw, at **2 linggo+**, mas tama ang counselor para mag-explore ng dahilan kaysa umasa sa chatbot.",

            'social_fear' => "Takot sa recit o group work—super common, lalo na kung feeling mo huhusgahan ka.\n\n"
                . "- Maghanda ng **isang pangungusap** na sasabihin mo; basahin sa bahay nang malakas isang beses.\n"
                . "- Umupo malapit sa friendly na kaklase.\n"
                . "- Gantimpalaan ang sarili pagkatapos ng klase, kahit maliit.\n\n"
                . "Kung racing heart at feeling mo mamatay ka sa panic, counselor—may exposure techniques sila.",

            'general_support' => "Salamat sa pagbabahagi—naririnig kita.\n\n"
                . "Para mas makatulong ako, sabihin kung ano ang pinaka-mabigat ngayon: **exam/school**, **tulog**, **kalungkutan**, **relasyon/pamilya**, o **kabog/panic**. Isang paragraph lang, sasagutin ko nang specific—hindi therapy, pero may praktikal na hakbang.",
        ];
    }

    return [
        'exam_school' => $how || $why
            ? "Exam stress can make your brain treat a test like real danger—that is why your heart races even when you \"know\" the material.\n\n"
                . "Try this tonight and tomorrow:\n"
                . "- **One block only:** 25 minutes review, 5 minutes off—not the whole course tonight.\n"
                . "- **Before you walk in:** one minute box breathing (in 4, hold 4, out 4, hold 4).\n"
                . "- **During the exam:** aim for *good enough* answers first—partial credit still counts.\n\n"
                . "If this is daily for **two+ weeks** and you cannot sleep or eat normally, your campus guidance office can help with a real plan—I cannot diagnose you, but they can support you."
            : "School pressure stacking up is exhausting—it usually means overload, not that you are incapable.\n\n"
                . "This week: pick **one subject** to prioritize, message one classmate or TA where you are stuck, and protect sleep before a cram night. Tell me if exams, projects, or grades are the main fear and I can narrow tips further.",

        'sleep' => $why
            ? "When you are tired but wired, it is often a stressed nervous system—not \"bad sleep habits\" alone.\n\n"
                . "Common contributors: unfinished tasks looping in your head, late screens, caffeine, or anxiety. For 3–5 nights try: **same wake time**, **paper list** of tomorrow's top 3, **no screens** 45–60 minutes before bed.\n\n"
                . "If this runs for weeks and days feel broken, see campus nurse or guidance—not a label from me, just human support."
            : "Being exhausted but unable to switch off is brutal.\n\n"
                . "1. **Wake** at the same time daily (even after short sleep).\n"
                . "2. **Off screens** 45–60 min before bed; write worries on paper.\n"
                . "3. **No heavy caffeine** in the afternoon.\n\n"
                . "If 2+ weeks of this wreck your days, a counselor can help more than struggling alone.",

        'loneliness' => "Feeling alone on campus hurts—and many students hide the same feeling.\n\n"
            . "You do not need a big friend group. Try:\n"
            . "- **One message** to a classmate about coursework (low pressure).\n"
            . "- **One club or meeting** you attend just once.\n"
            . "- **One person** you check in with weekly, even briefly.\n\n"
            . "If you tell me whether it is new environment, breakup, or never being invited, I can suggest a tighter next step.",

        'family' => "Family pressure often mixes love with fear of letting people down—that is heavy to carry while studying.\n\n"
            . "You can say calmly: *\"I hear you; I need to focus on [X] this week.\"* Pause heated talks; return when cooler.\n\n"
            . "If home is **unsafe** or abusive, you need a person/professional—not tips from a chatbot. Campus guidance can help you plan next steps.",

        'relationship' => "Relationship pain and breakups are real grief—not overreacting.\n\n"
            . "For now: do not force \"I'm fine\"; eat and sleep at a basic level; reduce painful social media triggers. Talk to one trusted person if you can.\n\n"
            . "If you cannot eat, sleep, or attend class for many days, see a counselor—heartbreak can deepen low mood.",

        'anxiety' => $how
            ? "When anxiety spikes, your body thinks there is danger—even if it is only a quiz or recit.\n\n"
                . "**Right now:**\n"
                . "- 5-4-3-2-1 grounding\n"
                . "- Box breathing: 4 in, 4 hold, 4 out, 4 hold\n"
                . "- Schedule a 15-minute **worry time later**, not all day\n\n"
                . "If panic is frequent and hard to breathe through, tell a counselor—they teach skills; I do not diagnose."
            : "Living on high alert is tiring—you are not weak, you are overloaded.\n\n"
                . "Try grounding or box breathing before class, cut afternoon caffeine, and do one small task per hour. If this runs for weeks, campus guidance helps.",

        'low_mood' => $why
            ? "Low mood often comes from stacked stress—sleep debt, exams, loneliness, conflict—not necessarily a disorder.\n\n"
                . "Today: **one small action** (shower, short walk, one meal). Avoid big decisions at the bottom.\n\n"
                . "If most days feel like this for **2+ weeks**, a professional check-in is wise and common on campus."
            : "Heavy, sad days are real—and you are not alone in this.\n\n"
                . "Today: one small win (food, 10-minute walk, one safe message). Repeat tomorrow. If nothing shifts for weeks, guidance counselor—I will not diagnose, but they can walk with you.",

        'stress_burnout' => "You sound burned out—that usually means too much output, too little recovery, not laziness.\n\n"
            . "This week:\n"
            . "- **Drop or delay** one non-essential commitment.\n"
            . "- **Protect** one rest hour like a class.\n"
            . "- **Pause** new responsibilities until you have a little energy back.\n\n"
            . "Rest is part of performance, not the opposite.",

        'help_seeking' => "Reaching out is a strong move.\n\n"
            . "**On campus:** start with guidance/counseling or student affairs—they know local referrals and student support.\n\n"
            . "**Philippines (urgent emotional crisis):** NCMH **1553** and numbers in this app's crisis response.\n\n"
            . "I am a chat companion only—I cannot handle emergencies.",

        'coping_how' => "Here is a simple plan many students use:\n\n"
            . "1. **Write** the problem in one sentence.\n"
            . "2. **Do** one action you can finish in 30 minutes.\n"
            . "3. **Tell** one person (even a short chat).\n"
            . "4. **Tonight:** what helped 5%? Repeat tomorrow.\n\n"
            . "Share whether school, sleep, people, or mood is hardest and I will tailor the next reply.",

        'why_feel' => "Asking *why* you feel this way is completely fair.\n\n"
            . "Common contributors: **stress**, **poor sleep**, **exam pressure**, **loneliness**, or **conflict** at home or in a relationship. That does not automatically mean a disorder—it can be your situation right now.\n\n"
            . "If it is intense, daily, and lasts **2+ weeks**, a counselor can explore causes properly—better than relying on a chatbot.",

        'social_fear' => "Fear of recitation or being judged in class is very common.\n\n"
            . "- Prepare **one sentence** you will say; say it aloud once at home.\n"
            . "- Sit near someone friendly.\n"
            . "- Reward yourself after class, even small.\n\n"
            . "If panic feels physical and scary (racing heart, faint), mention it to a counselor.",

        'general_support' => "Thank you for opening up—I am listening.\n\n"
            . "To answer you better: what hurts most right now—**school/exams**, **sleep**, **loneliness**, **family/relationship**, or **anxiety/panic**? A few sentences is enough and I will respond with specific steps (support only, not therapy).",
    ];
}

function wellness_ph_crises_resources_public(): array
{
    return [
        'cards' => [
            [
                'type' => 'ncmh',
                'title' => 'National Center for Mental Health (NCMH) Crisis Hotline',
                'lines' => [
                    'Toll-free (landline nationwide): **1553**',
                    'Globe/TM mobile: **0917-899-8727** (USAP)',
                    'Mobile / Smart: **0966-351-4518**, **0919-057-1553**',
                ],
            ],
            [
                'type' => 'hopeline_touch',
                'title' => 'HOPELINE (In Touch Crisis Line)',
                'lines' => [
                    '**0917-558-HOPE (4673)**',
                    '**2919** (Globe/TM toll-free shortcut, when available)',
                ],
            ],
            [
                'type' => 'emergency',
                'title' => 'Immediate danger — Philippines',
                'lines' => [
                    'If harm is imminent, call emergency services (**911**) or go to your nearest ER/hospital.',
                ],
            ],
        ],
    ];
}

/** @param 'en'|'tl' $lang */
function wellness_disclaimer_banner(string $lang): string
{
    if ($lang === 'tl') {
        return 'Paalala: Hindi ako therapist, hindi nagdi-diagnose, at hindi pamalit sa emergency. '
            . 'Sa krisis, gamitin ang hotline sa Pilipinas (makikita sa crisis response).';
    }

    return 'Reminder: I am not a therapist, I do not diagnose, and I am not for emergencies. '
        . 'In crisis, use Philippines hotlines (included in crisis responses).';
}

/** @param 'en'|'tl' $lang */
function wellness_footer_disclaimer(string $lang): string
{
    return $lang === 'tl'
        ? 'Ito ay pang-edukasyon at suporta lamang—hindi medikal na payo.'
        : 'Educational support only—not medical advice.';
}

/** @param 'en'|'tl' $lang */
function wellness_crisis_response(string $lang): array
{
    $res = wellness_ph_crises_resources_public();
    $linesBullet = '';
    foreach ($res['cards'] as $card) {
        $linesBullet .= "### {$card['title']}\n";
        foreach ($card['lines'] as $ln) {
            $linesBullet .= '- ' . $ln . "\n";
        }
        $linesBullet .= "\n";
    }

    if ($lang === 'tl') {
        $reply = "**Salamat at sinabi mo ito.** Kung pinag-iisipan mong saktan ang sarili o tapusin ang buhay, kailangan mo ng **totoong tao** ngayon.\n\n"
            . "- **Agad na panganib:** tumawag sa **911** o ER/hospital.\n"
            . "- **Pilipinas — crisis lines:**\n\n"
            . $linesBullet
            . 'Huwag mag-isang maghintay kung hindi ligtas—manatili sa linya o kasama ng taong pinagkakatiwalaan mo.';
    } else {
        $reply = "**Thank you for telling someone—even here.** If you might harm yourself or end your life, please reach a **real person** now.\n\n"
            . "- **Immediate danger:** call **911** or go to the nearest ER.\n"
            . "- **Philippines crisis lines:**\n\n"
            . $linesBullet
            . 'Do not wait alone if you feel unsafe—stay on the line or with someone you trust.';
    }

    return ['reply' => trim($reply), 'resources' => $res];
}

/**
 * @return array{
 *     reply: string,
 *     reply_body: string,
 *     crisis: bool,
 *     topics: list<string>,
 *     intent: string,
 *     scenarios: list<string>,
 *     is_question: bool,
 *     disclaimer_banner: string,
 *     footer_disclaimer: string,
 *     language: string,
 *     reply_source: string,
 *     resources?: array
 * }
 */
/**
 * @param list<array{role: string, content: string}> $history
 */
function wellness_chat_orchestrate(string $message, ?string $langHint, array $history = []): array
{
    $norm = wellness_normalize_for_match($message);
    $language = wellness_resolve_language($langHint, $norm);
    /** @var 'en'|'tl' $language */

    $banner = wellness_disclaimer_banner($language);
    $footer = wellness_footer_disclaimer($language);
    $isQuestion = wellness_message_is_question($norm);

    if ($norm === '') {
        $body = $language === 'tl'
            ? 'Magtanong o magkwento ng konti—hal. *Paano ko haharapin ang exam anxiety?* o *Sobrang pagod na ako sa school.* Sasagutin ko ayon sa tanong mo (Tagalog/English).'
            : 'Ask or share a little—e.g. *How do I handle exam anxiety?* or *I am burned out from school.* I will answer based on your question (English or Tagalog).';

        return [
            'reply' => $body,
            'reply_body' => $body,
            'crisis' => false,
            'topics' => [],
            'intent' => 'prompt',
            'scenarios' => [],
            'is_question' => false,
            'disclaimer_banner' => $banner,
            'footer_disclaimer' => $footer,
            'language' => $language,
            'reply_source' => 'builtin',
        ];
    }

    if (wellness_is_crisis_message($norm)) {
        $cr = wellness_crisis_response($language);

        return [
            'reply' => $cr['reply'],
            'reply_body' => $cr['reply'],
            'crisis' => true,
            'topics' => [],
            'intent' => 'crisis',
            'scenarios' => ['crisis'],
            'is_question' => $isQuestion,
            'disclaimer_banner' => $banner,
            'footer_disclaimer' => $footer,
            'language' => $language,
            'resources' => $cr['resources'],
            'reply_source' => 'crisis',
        ];
    }

    $class = wellness_classify($norm);
    $scores = wellness_score_scenarios($norm);
    $primary = $class['scenarios'][0] ?? 'general_support';
    $topics = wellness_topics_from_scenarios($scores);
    $messageTrim = trim($message);

    $replySource = 'smart';
    $replyBody = null;

    require_once dirname(__DIR__) . '/includes/wellness_ai.php';
    if (wellness_ai_is_enabled()) {
        $aiReply = wellness_ai_chat($messageTrim, $language, $class['scenarios'], $history);
        if ($aiReply !== null && $aiReply !== '') {
            $replyBody = $aiReply;
            $provider = wellness_ai_provider();
            if ($provider === 'groq') {
                $replySource = 'groq';
            } elseif ($provider === 'ollama' || ($provider === 'auto' && wellness_ollama_reachable() && !wellness_ai_has_cloud_key())) {
                $replySource = 'ollama';
            } elseif ($provider === 'gemini') {
                $replySource = 'gemini';
            } else {
                $replySource = 'ai';
            }
        }
    }

    if ($replyBody === null) {
        require_once dirname(__DIR__) . '/includes/wellness_engine.php';
        $replyBody = wellness_engine_reply($messageTrim, $language, $primary, $class['scenarios'], $norm);
        $replySource = 'smart';
    }

    return [
        'reply' => $replyBody,
        'reply_body' => $replyBody,
        'crisis' => false,
        'topics' => $topics,
        'intent' => $class['intent'],
        'scenarios' => $class['scenarios'],
        'is_question' => $isQuestion,
        'disclaimer_banner' => $banner,
        'footer_disclaimer' => $footer,
        'language' => $language,
        'reply_source' => $replySource,
    ];
}
