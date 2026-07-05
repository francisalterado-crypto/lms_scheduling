<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/student_materials_reviewer.php';
require_once __DIR__ . '/includes/wellness_ai.php';

require_role(['student']);

$studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($studentId < 1) {
    $studentId = resolve_student_id_for_user($userId) ?? 0;
    $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
}
if ($studentId < 1) {
    exit('Student profile not linked to this account. Ask your instructor to create or link your student profile.');
}

$requiredTables = [
    'online_classrooms',
    'classroom_enrollments',
    'classroom_content',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));

$classes = [];
$weekGroups = [];
$selectedClassroomId = (int) ($_GET['classroom_id'] ?? 0);
$selectedWeek = trim((string) ($_GET['week'] ?? ''));
$aiAvailable = wellness_ai_is_enabled();

if ($missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.id, c.course_code, c.course_name, f.full_name AS faculty_name
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         INNER JOIN courses c ON c.id = oc.course_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         WHERE ce.student_id = ?
         ORDER BY c.course_code ASC, oc.id ASC'
    );
    $st->execute([$studentId]);
    $classes = $st->fetchAll();

    if ($selectedClassroomId < 1 && $classes !== []) {
        $selectedClassroomId = (int) $classes[0]['id'];
    }

    $validClassroom = false;
    foreach ($classes as $row) {
        if ((int) $row['id'] === $selectedClassroomId) {
            $validClassroom = true;
            break;
        }
    }
    if (!$validClassroom) {
        $selectedClassroomId = $classes !== [] ? (int) $classes[0]['id'] : 0;
    }

    if ($selectedClassroomId > 0) {
        $weekGroups = student_materials_reviewer_week_groups($selectedClassroomId);
        if ($selectedWeek === '' && $weekGroups !== []) {
            $selectedWeek = (string) $weekGroups[0]['label'];
        }
        $weekLabels = array_map(static fn (array $g): string => (string) $g['label'], $weekGroups);
        if ($selectedWeek !== '' && !in_array($selectedWeek, $weekLabels, true)) {
            $selectedWeek = $weekGroups !== [] ? (string) $weekGroups[0]['label'] : '';
        }
    }
}

$selectedClassroom = null;
foreach ($classes as $row) {
    if ((int) $row['id'] === $selectedClassroomId) {
        $selectedClassroom = $row;
        break;
    }
}

$pageTitle = 'Weekly materials reviewer';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 student-page-header">
    <div class="min-w-0">
        <h1 class="h3 mb-1">
            <i class="fa-solid fa-clipboard-question me-2 text-primary"></i>Weekly materials reviewer
        </h1>
        <p class="text-muted small mb-0">
            Generate practice questions from your instructor's posted course materials, one week at a time.
            <?php if ($aiAvailable): ?>
                AI-enhanced questions are enabled when materials are available.
            <?php else: ?>
                Questions are generated from your weekly materials (enable Wellness AI in config for richer AI questions).
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 student-page-header__actions">
        <a href="student_classrooms.php" class="btn btn-outline-primary btn-sm"<?= student_tooltip_attr('Opens all your enrolled classes.') ?>><i class="fa-solid fa-user-graduate me-1"></i>My Classes</a>
    </div>
</div>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning">
        Classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php elseif ($classes === []): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted mb-3">You are not enrolled in any class yet. Join a class first, then return here to review materials by week.</p>
            <a href="student_classrooms.php" class="btn btn-primary btn-sm">Go to My Classes</a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4 student-materials-reviewer-layout">
        <div class="col-lg-4 order-lg-1 order-1">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong>Select class and week</strong></div>
                <div class="card-body">
                    <form method="get" action="student_materials_reviewer.php" class="mb-3">
                        <label class="form-label small text-muted" for="classroom_id">Class</label>
                        <select class="form-select form-select-sm mb-3" id="classroom_id" name="classroom_id" onchange="this.form.submit()">
                            <?php foreach ($classes as $row): ?>
                                <option value="<?= (int) $row['id'] ?>"<?= (int) $row['id'] === $selectedClassroomId ? ' selected' : '' ?>>
                                    <?= htmlspecialchars((string) $row['course_code']) ?> — <?= htmlspecialchars((string) $row['course_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if ($weekGroups !== []): ?>
                            <label class="form-label small text-muted" for="week">Week</label>
                            <select class="form-select form-select-sm" id="week" name="week" onchange="this.form.submit()">
                                <?php foreach ($weekGroups as $group): ?>
                                    <?php $label = (string) $group['label']; ?>
                                    <option value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"<?= $label === $selectedWeek ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?> (<?= (int) $group['count'] ?> item<?= (int) $group['count'] === 1 ? '' : 's' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <p class="small text-muted mb-0">No weekly materials posted for this class yet.</p>
                        <?php endif; ?>
                    </form>

                    <?php if ($selectedClassroom): ?>
                        <div class="small text-muted border-top pt-3">
                            <div><strong>Instructor:</strong> <?= htmlspecialchars((string) $selectedClassroom['faculty_name']) ?></div>
                            <?php if ($selectedWeek !== ''): ?>
                                <div class="mt-1"><strong>Selected:</strong> <?= htmlspecialchars($selectedWeek) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($selectedClassroomId > 0 && $selectedWeek !== ''): ?>
                        <div class="mt-3 d-grid gap-2">
                            <a href="student_classroom_week.php?id=<?= (int) $selectedClassroomId ?>&week=<?= rawurlencode($selectedWeek) ?>" class="btn btn-outline-secondary btn-sm"<?= student_tooltip_attr('Opens the full materials for this week.') ?>>
                                <i class="fa-regular fa-folder-open me-1"></i>View week materials
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8 order-lg-2 order-2">
            <div class="card shadow-sm" id="reviewer-panel"
                 data-classroom-id="<?= (int) $selectedClassroomId ?>"
                 data-week="<?= htmlspecialchars($selectedWeek, ENT_QUOTES, 'UTF-8') ?>"
                 data-api="<?= htmlspecialchars('api/student_materials_review_questions.php', ENT_QUOTES, 'UTF-8') ?>">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Practice questions</strong>
                    <?php if ($selectedWeek !== ''): ?>
                        <span class="badge text-bg-light border"><?= htmlspecialchars($selectedWeek) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($weekGroups === []): ?>
                        <p class="text-muted mb-0">Your instructor has not posted course materials for this class yet. Check back after materials are uploaded.</p>
                    <?php else: ?>
                        <p class="small text-muted">Generate as many review questions as needed from materials posted for the selected week. Click again to add more.</p>
                        <div class="d-flex flex-wrap gap-2 mb-3 student-reviewer-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="reviewer-generate-btn"<?= student_tooltip_attr('Creates practice questions from this week\'s posted materials (no limit).') ?>>
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate questions
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm d-none" id="reviewer-more-btn"<?= student_tooltip_attr('Adds more new practice questions without removing the ones already shown.') ?>>
                                <i class="fa-solid fa-plus me-1"></i>Generate more
                            </button>
                        </div>
                        <div id="reviewer-status" class="small text-muted mb-2 d-none"></div>
                        <div id="reviewer-questions" class="reviewer-questions-list"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const panel = document.getElementById('reviewer-panel');
        const btn = document.getElementById('reviewer-generate-btn');
        const moreBtn = document.getElementById('reviewer-more-btn');
        const statusEl = document.getElementById('reviewer-status');
        const listEl = document.getElementById('reviewer-questions');
        if (!panel || !btn || !listEl) {
            return;
        }

        let existingQuestions = [];
        let metaEl = null;

        function setStatus(text, isError) {
            if (!statusEl) {
                return;
            }
            statusEl.textContent = text;
            statusEl.classList.toggle('text-danger', !!isError);
            statusEl.classList.toggle('text-muted', !isError);
            statusEl.classList.remove('d-none');
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function buildQuestionCard(q, displayIndex) {
            const card = document.createElement('div');
            card.className = 'reviewer-question-card';

            let inner = '<div class="fw-semibold mb-2">Q' + displayIndex + '. ' + escapeHtml(q.question || '') + '</div>';
            if (q.type === 'multiple_choice' && Array.isArray(q.choices) && q.choices.length) {
                inner += '<div class="small mb-2">';
                q.choices.forEach(function (choice) {
                    inner += '<label class="reviewer-choice"><input type="radio" disabled name="q' + q.id + '" class="me-2">'
                        + escapeHtml(choice) + '</label>';
                });
                inner += '</div>';
            }
            inner += '<button type="button" class="btn btn-outline-secondary btn-sm reviewer-reveal-btn">Show answer</button>';
            inner += '<div class="reviewer-answer small"><strong>Answer:</strong> ' + escapeHtml(q.answer || '') + '</div>';
            card.innerHTML = inner;

            const revealBtn = card.querySelector('.reviewer-reveal-btn');
            if (revealBtn) {
                revealBtn.addEventListener('click', function () {
                    card.classList.toggle('is-revealed');
                    revealBtn.textContent = card.classList.contains('is-revealed') ? 'Hide answer' : 'Show answer';
                });
            }

            return card;
        }

        function updateMeta(totalCount, source, materialCount, appendNote) {
            if (!metaEl) {
                metaEl = document.createElement('p');
                metaEl.className = 'small text-muted reviewer-meta';
                listEl.prepend(metaEl);
            }
            let text = totalCount + ' question' + (totalCount === 1 ? '' : 's') + ' shown';
            if (source === 'ai') {
                text += ' (AI-assisted)';
            }
            if (materialCount != null) {
                text += ' • ' + materialCount + ' material(s) in this week';
            }
            if (appendNote) {
                text += ' • added ' + appendNote + ' more';
            }
            metaEl.textContent = text;
        }

        function renderAllQuestions() {
            const cards = listEl.querySelectorAll('.reviewer-question-card');
            cards.forEach(function (card) {
                card.remove();
            });

            existingQuestions.forEach(function (q, idx) {
                listEl.appendChild(buildQuestionCard(q, idx + 1));
            });

            if (existingQuestions.length === 0) {
                if (metaEl) {
                    metaEl.remove();
                    metaEl = null;
                }
                return;
            }

            updateMeta(existingQuestions.length, existingQuestions[0].source || 'builtin', null, '');
        }

        async function requestQuestions(append) {
            const classroomId = parseInt(panel.dataset.classroomId || '0', 10);
            const week = panel.dataset.week || '';
            const api = panel.dataset.api || '';
            if (!classroomId || !week || !api) {
                setStatus('Select a class and week first.', true);
                return;
            }

            const activeBtn = append ? moreBtn : btn;
            if (activeBtn) {
                activeBtn.disabled = true;
            }
            if (!append) {
                btn.disabled = true;
            }
            setStatus(append ? 'Generating more questions…' : 'Generating questions…', false);

            if (!append) {
                existingQuestions = [];
                listEl.innerHTML = '';
                metaEl = null;
            }

            try {
                const exclude = append
                    ? existingQuestions.map(function (q) { return q.question || ''; })
                    : [];
                const res = await fetch(api, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        classroom_id: classroomId,
                        week: week,
                        count: 0,
                        exclude: exclude,
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || 'Could not generate questions.');
                }

                const incoming = Array.isArray(data.questions) ? data.questions : [];
                if (incoming.length === 0) {
                    setStatus(append
                        ? 'No additional questions could be generated right now. Try again later or switch weeks.'
                        : 'No questions could be generated for this week.', true);
                    if (!append) {
                        listEl.innerHTML = '<p class="text-muted mb-0">No questions could be generated for this week.</p>';
                    }
                    return;
                }

                const startIndex = existingQuestions.length;
                incoming.forEach(function (q, idx) {
                    q.id = startIndex + idx + 1;
                    existingQuestions.push(q);
                });

                if (append) {
                    incoming.forEach(function (q, idx) {
                        listEl.appendChild(buildQuestionCard(q, startIndex + idx + 1));
                    });
                    updateMeta(existingQuestions.length, data.source || 'builtin', data.material_count, incoming.length);
                } else {
                    renderAllQuestions();
                    if (metaEl) {
                        updateMeta(existingQuestions.length, data.source || 'builtin', data.material_count, '');
                    }
                }

                if (moreBtn) {
                    moreBtn.classList.remove('d-none');
                }
                setStatus('', false);
                statusEl.classList.add('d-none');
            } catch (err) {
                setStatus(err.message || 'Something went wrong.', true);
            } finally {
                btn.disabled = false;
                if (moreBtn) {
                    moreBtn.disabled = false;
                }
            }
        }

        btn.addEventListener('click', function () {
            requestQuestions(false);
        });
        if (moreBtn) {
            moreBtn.addEventListener('click', function () {
                requestQuestions(true);
            });
        }
    })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
