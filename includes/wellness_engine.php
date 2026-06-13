<?php
declare(strict_types=1);

/**
 * Dynamic wellness replies without external AI — reads the student's message and answers directly.
 */

/**
 * @return array{
 *   emotions: list<string>,
 *   topics: list<string>,
 *   time_hint: ?string,
 *   question: ?string,
 *   intensity: string
 * }
 */
function wellness_parse_student_message(string $message, string $norm): array
{
    $emotions = [];
    $emotionMap = [
        'anxious' => ['anxious', 'anxiety', 'nababalisa', 'kabado', 'kinakabahan', 'panic', 'takot', 'nerbiyos'],
        'sad' => ['sad', 'depressed', 'malungkot', 'lungkot', 'iyak', 'umiiyak', 'empty', 'walang gana'],
        'tired' => ['tired', 'exhausted', 'pagod', 'burnout', 'drained', 'walang lakas'],
        'lonely' => ['lonely', 'alone', 'mag-isa', 'nag-iisa', 'walang kaibigan', 'isolated'],
        'angry' => ['angry', 'galit', 'frustrated', 'inis', 'naiirita'],
        'overwhelmed' => ['overwhelm', 'sobra', 'daming', 'lahat', 'hindi ko na kaya', 'cant handle'],
    ];
    foreach ($emotionMap as $key => $words) {
        foreach ($words as $w) {
            if (mb_strpos($norm, $w, 0, 'UTF-8') !== false) {
                $emotions[] = $key;
                break;
            }
        }
    }

    $topics = [];
    $topicMap = [
        'exam' => ['exam', 'quiz', 'test', 'midterm', 'final', 'pagsusulit'],
        'school' => ['school', 'class', 'prof', 'assignment', 'project', 'grades', 'aral', 'pasahan'],
        'sleep' => ['sleep', 'tulog', 'insomnia', 'makakatulog', 'puyat', 'gising'],
        'family' => ['family', 'parents', 'mom', 'dad', 'nanay', 'tatay', 'magulang'],
        'relationship' => ['breakup', 'boyfriend', 'girlfriend', 'jowa', 'ex ', 'relasyon'],
        'friends' => ['friend', 'kaibigan', 'classmate'],
    ];
    foreach ($topicMap as $key => $words) {
        foreach ($words as $w) {
            if (mb_strpos($norm, $w, 0, 'UTF-8') !== false) {
                $topics[] = $key;
                break;
            }
        }
    }

    $timeHint = null;
    foreach (['bukas', 'tomorrow', 'tonight', 'ngayong gabi', 'later today', 'this week', 'ngayon'] as $t) {
        if (mb_strpos($norm, $t, 0, 'UTF-8') !== false) {
            $timeHint = $t;
            break;
        }
    }

    $question = null;
    if (preg_match('/^\s*(how|paano|what|ano|why|bakit|can i|pwede|should i)/u', $norm)) {
        $question = preg_match('/\b(why|bakit)\b/u', $norm) ? 'why' : (preg_match('/\b(how|paano)\b/u', $norm) ? 'how' : 'what');
    } elseif (str_contains($norm, '?')) {
        $question = preg_match('/\b(why|bakit)\b/u', $norm) ? 'why' : (preg_match('/\b(how|paano)\b/u', $norm) ? 'how' : 'general');
    }

    $intensity = 'moderate';
    foreach (['sobra', 'very', 'really', 'so ', 'labis', 'hindi ko na', 'cant', "can't"] as $i) {
        if (mb_strpos($norm, $i, 0, 'UTF-8') !== false) {
            $intensity = 'high';
            break;
        }
    }

    return [
        'emotions' => array_values(array_unique($emotions)),
        'topics' => array_values(array_unique($topics)),
        'time_hint' => $timeHint,
        'question' => $question,
        'intensity' => $intensity,
    ];
}

/** @param array{emotions: list<string>, topics: list<string>, time_hint: ?string, question: ?string, intensity: string} $ctx */
function wellness_empathy_opening(array $ctx, string $lang, string $primary): string
{
    $e = $ctx['emotions'][0] ?? null;
    $t = $ctx['topics'][0] ?? null;
    $time = $ctx['time_hint'];

    if ($lang === 'tl') {
        if ($t === 'exam' && $time !== null && (str_contains((string) $time, 'bukas') || str_contains((string) $time, 'tomorrow'))) {
            return 'Naiintindihan ko—bukas ang exam at mabigat na ang pakiramdam mo ngayon. Normal ang kaba, pero pwedeng gawing mas kaya ang gabi at umaga mo.';
        }
        if ($t === 'sleep') {
            return 'Nakakapagod kapag gusto mong magpahinga pero hindi sumusuko ang utak mo—valid ang pagod mo.';
        }
        if ($t === 'friends' || $e === 'lonely') {
            return 'Masakit ang pakiramdam na wala kang kasama o hindi ka makaconnect—maraming estudyante ang dumadaan dito sa campus.';
        }
        if ($e === 'anxious') {
            return 'Kapag sobrang kaba, parang laging may mangyayaring masama—hindi ka mahina, overloaded lang ang katawan mo.';
        }
        if ($e === 'sad') {
            return 'Malungkot at mabigat ang araw mo—naririnig kita at hindi mo kailangang magdala nito nang mag-isa.';
        }
        if ($e === 'tired') {
            return 'Pagod na pagod ka—sign ito na kailangan mo ng pahinga, hindi ng mas maraming pressure sa sarili.';
        }

        return 'Salamat sa pagbabahagi—naririnig kita at valid ang nararamdaman mo.';
    }

    if ($t === 'exam' && $time !== null && (str_contains((string) $time, 'tomorrow') || str_contains((string) $time, 'bukas'))) {
        return 'I hear you—tomorrow\'s exam is weighing on you tonight. That kind of dread is common, and you can still make the next hours more manageable.';
    }
    if ($t === 'sleep') {
        return 'Wanting rest while your mind stays awake is exhausting—and it makes sense you feel worn down.';
    }
    if ($t === 'friends' || $e === 'lonely') {
        return 'Feeling disconnected or without people who get you really hurts—many students feel this on campus, even when it looks like everyone else is fine.';
    }
    if ($e === 'anxious') {
        return 'When anxiety spikes, it can feel like something bad is about to happen—even if you logically know you\'re "fine." That\'s your nervous system on high alert, not weakness.';
    }
    if ($e === 'sad') {
        return 'Heavy, sad days are real—and you don\'t have to pretend they\'re not.';
    }
    if ($e === 'tired') {
        return 'You sound deeply tired—that usually means you need recovery, not more self-criticism.';
    }

    return 'Thank you for trusting me with this—I\'m listening, and what you feel matters.';
}

/**
 * @param array{emotions: list<string>, topics: list<string>, time_hint: ?string, question: ?string, intensity: string} $ctx
 */
function wellness_core_steps(array $ctx, string $lang, string $primary): string
{
    $q = $ctx['question'];
    $topics = $ctx['topics'];
    $top = $topics[0] ?? $primary;

    if ($lang === 'tl') {
        if ($top === 'exam' || $primary === 'exam_school') {
            if ($q === 'how') {
                return "**Ngayong gabi:**\n"
                    . "1. Piliin **isang topic** lang na ire-review (hindi buong course).\n"
                    . "2. **25 min** study → **5 min** walk/tubig.\n"
                    . "3. Isulat sa papel: *\"Bukas, first thing I do is…\"* (isang konkretong hakbang).\n\n"
                    . "**Bago exam:** 1 minutong box breathing; dalhin tubig; target **good enough**, hindi perpekto.\n\n"
                    . "Kung halos araw-araw ganito at hindi ka makatulog ng 2+ linggo, campus guidance makakatulong.";
            }

            return "**Maaaring tumulong:**\n"
                . "• Hatiin ang aral sa maliliit na bloke—huwag buong syllabus sa isang upuan.\n"
                . "• Magtanong sa kaklase/TA kung saan stuck ka.\n"
                . "• Protektahan ang tulog bago huling gabing cram.";
        }
        if ($top === 'sleep' || $primary === 'sleep') {
            return "**Subukan ngayon:**\n"
                . "1. Isulat sa papel ang **3 priority bukas** — para hindi umiikot sa utak sa kama.\n"
                . "2. **Screen off** 45–60 min bago matulog.\n"
                . "3. **Parehong oras ng gising** kahit kulang tulog (3–5 araw).\n\n"
                . "Kung 2+ linggo nang sabog ang araw mo, nurse o guidance—hindi ito diagnosis, pero kailangan ng tao.";
        }
        if (($ctx['emotions'][0] ?? '') === 'lonely' || $top === 'friends' || $primary === 'loneliness') {
            return "**Maliit na hakbang ngayong linggo:**\n"
                . "1. **Isang mensahe** sa kaklase tungkol sa subject (mababang pressure).\n"
                . "2. **Isang org/meeting** — dumalo lang ng isang beses.\n"
                . "3. **Isang tao** na i-check-in mo kahit once a week.\n\n"
                . "Hindi kailangan maging close sa lahat—isa lang na consistent ay malaki na.";
        }
        if ($primary === 'anxiety' || ($ctx['emotions'][0] ?? '') === 'anxious') {
            return "**Para sa kaba ngayon:**\n"
                . "1. **Box breathing** — 4 in, 4 hold, 4 out, 4 hold (3 ulit).\n"
                . "2. **Grounding** — 5 bagay na nakikita, 4 nahahawakan.\n"
                . "3. **Worry window** — 15 min mamaya ang pag-iisip, hindi ngayon.\n\n"
                . "Bawasan caffeine bago klase. Kung halos araw-araw panic, guidance counselor.";
        }
        if ($primary === 'family') {
            return "**Sa pressure ng pamilya:**\n"
                . "1. Sabihin ang limit mo nang kalmado: *\"Naririnig kita; kailangan ko mag-focus sa [X] ngayong linggo.\"*\n"
                . "2. Huminto kung mainit ang usapan; bumalik kapag kalmado.\n"
                . "3. Guidance kung paulit-ulit at hindi ka na makapag-aral.\n\n"
                . "Kung **hindi ligtas** ang bahay, kailangan ng tao/propesyunal—hindi chatbot.";
        }
        if ($primary === 'relationship') {
            return "**Sa sakit ng relasyon/breakup:**\n"
                . "1. Payagan ang sarili na magluksa—walang deadline sa \"move on.\"\n"
                . "2. Bawasan ang social media kung trigger.\n"
                . "3. Isang taong mapagkakatiwalaan ang kausapin.\n\n"
                . "Kung hindi kumakain/matulog ng maraming araw, guidance counselor.";
        }
        if ($primary === 'stress_burnout' || ($ctx['emotions'][0] ?? '') === 'tired') {
            return "**Sa burnout/pagod:**\n"
                . "1. **Alisin o ipagpaliban** ang isang hindi critical na commitment ngayong linggo.\n"
                . "2. **Isang oras** ng pahinga—tulad ng importance ng klase.\n"
                . "3. **Huwag** magdagdag ng bagong task hanggang may konting gana.\n\n"
                . "Ang pahinga ay parte ng productivity, hindi katamaran.";
        }
        if ($q === 'why') {
            return "Madalas ang bigat ng mood dahil sa **stack**: kulang tulog, exam pressure, kalungkutan, o away—**hindi** agad ibig sabihin may \"sakit\" ka.\n\n"
                . "Kung **araw-araw** at **2+ linggo** na, mas tama ang counselor para mag-explore—ako pang-edukasyon lang.";
        }

        return "**Ngayon:**\n"
            . "1. Pangalanan ang pinaka-mabigat (aral / tulog / tao / mood).\n"
            . "2. Gawin **isang** aksyon na kaya sa 30 min.\n"
            . "3. Sabihin sa **isang tao** kung safe.\n\n"
            . "Sabihin kung ano ang pinaka-mabigat para mas i-target ang susunod na sagot.";
    }

    // English
    if ($top === 'exam' || $primary === 'exam_school') {
        if ($q === 'how') {
            return "**Tonight:**\n"
                . "1. Pick **one topic** to review—not the whole course.\n"
                . "2. **25 min** study → **5 min** break (water/walk).\n"
                . "3. On paper, write: *\"Tomorrow, the first thing I do is…\"* (one concrete step).\n\n"
                . "**Before the exam:** 1 minute box breathing; bring water; aim for **good enough**, not perfect.\n\n"
                . "If this is daily for **2+ weeks** and sleep is wrecked, campus guidance can help with a real plan.";
        }

        return "**What often helps:**\n"
            . "• Break study into small blocks—avoid cramming the entire syllabus at once.\n"
            . "• Ask a classmate or TA where you're stuck.\n"
            . "• Protect sleep the night before—recall beats last-minute panic.";
    }
    if ($top === 'sleep' || $primary === 'sleep') {
        return "**Try tonight:**\n"
            . "1. Write tomorrow's **top 3 tasks** on paper so your brain can stop looping.\n"
            . "2. **No screens** 45–60 minutes before bed.\n"
            . "3. Same **wake time** daily for 3–5 days, even after short sleep.\n\n"
            . "If this lasts **2+ weeks** and days feel broken, see campus nurse or guidance—not a diagnosis from me, just human support.";
    }
    if (($ctx['emotions'][0] ?? '') === 'lonely' || $top === 'friends' || $primary === 'loneliness') {
        return "**Small steps this week:**\n"
            . "1. **One message** to a classmate about coursework (low pressure).\n"
            . "2. **One club or meeting**—show up once.\n"
            . "3. **One person** to check in with weekly.\n\n"
            . "You don't need a big friend group—one steady connection can shift how alone you feel.";
    }
    if ($primary === 'anxiety' || ($ctx['emotions'][0] ?? '') === 'anxious') {
        return "**For anxiety right now:**\n"
            . "1. **Box breathing** — in 4, hold 4, out 4, hold 4 (repeat 3 times).\n"
            . "2. **Grounding** — name 5 things you see, 4 you can touch.\n"
            . "3. **Worry window** — schedule 15 minutes later for rumination, not all day.\n\n"
            . "Cut afternoon caffeine before class. If panic is frequent, see guidance counselor.";
    }
    if ($primary === 'family') {
        return "**With family pressure:**\n"
            . "1. State a calm limit: *\"I hear you; I need to focus on [X] this week.\"*\n"
            . "2. Pause heated talks; return when cooler.\n"
            . "3. Use guidance if it keeps blocking school.\n\n"
            . "If home is **unsafe**, you need a person/professional—not chat tips.";
    }
    if ($primary === 'relationship') {
        return "**With relationship pain or breakup:**\n"
            . "1. Let yourself grieve—no forced timeline to \"be over it.\"\n"
            . "2. Reduce social media if it's a trigger.\n"
            . "3. Talk to one trusted person.\n\n"
            . "If you can't eat, sleep, or attend class for many days, see a counselor.";
    }
    if ($primary === 'stress_burnout' || ($ctx['emotions'][0] ?? '') === 'tired') {
        return "**For burnout and exhaustion:**\n"
            . "1. **Drop or delay** one non-critical commitment this week.\n"
            . "2. **Protect one hour** of rest like a class.\n"
            . "3. **Don't** add new tasks until you regain a little energy.\n\n"
            . "Rest is part of doing well, not the opposite.";
    }
    if ($q === 'why') {
        return "Low mood or worry often comes from a **stack** of stress, poor sleep, exams, loneliness, or conflict—that doesn't automatically mean a disorder.\n\n"
            . "If it's **most days for 2+ weeks**, a counselor can explore causes properly; I'm only here for general support.";
    }

    return "**Right now:**\n"
        . "1. Name what's heaviest (school / sleep / people / mood).\n"
        . "2. Do **one** action you can finish in 30 minutes.\n"
        . "3. Tell **one safe person** if you can.\n\n"
        . "Share which area hurts most if you want a more targeted reply next.";
}

/** @param array{emotions: list<string>, topics: list<string>, time_hint: ?string, question: ?string, intensity: string} $ctx */
function wellness_soft_close(array $ctx, string $lang): string
{
    if ($lang === 'tl') {
        return 'Hindi ako therapist o emergency line—kung lumala o tumagal ng linggo, campus guidance ang tamang susunod na hakbang.';
    }

    return 'I\'m not a therapist or emergency line—if this stays intense for weeks, your campus guidance office is the right next step.';
}

/**
 * Best-effort local reply tailored to the student's words.
 *
 * @param list<string> $scenarios
 */
function wellness_engine_reply(string $message, string $lang, string $primary, array $scenarios, string $norm): string
{
    $ctx = wellness_parse_student_message($message, $norm);

    if ($primary === 'coping_how' && isset($scenarios[1])) {
        $primary = $scenarios[1];
    }

    $parts = [
        wellness_empathy_opening($ctx, $lang, $primary),
        wellness_core_steps($ctx, $lang, $primary),
        wellness_soft_close($ctx, $lang),
    ];

    return trim(implode("\n\n", $parts));
}
