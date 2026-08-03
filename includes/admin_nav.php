<?php
declare(strict_types=1);

/**
 * Shared sidebar navigation: grouped sections with icons (admin + role dashboards).
 *
 * @param array<string, list<array{file:string,href:string,icon:string,label:string,badge?:int,tooltip?:string,target?:string}>> $sections
 */
function render_nav_sections_markup(array $sections, string $currentPage, bool $dismissOffcanvas): void
{
    foreach ($sections as $title => $items) {
        echo '<div class="admin-nav-section" role="group" aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="admin-nav-section-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<ul class="admin-nav-list list-unstyled mb-0">';
        foreach ($items as $item) {
            $isActive = $currentPage === $item['file'];
            $badge = isset($item['badge']) ? (int) $item['badge'] : 0;
            $classes = 'admin-nav-link' . ($isActive ? ' active' : '');
            echo '<li>';
            echo '<a class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') . '"';
            if ($isActive) {
                echo ' aria-current="page"';
            }
            if ($dismissOffcanvas) {
                echo ' data-bs-dismiss="offcanvas"';
            }
            if (!empty($item['tooltip'])) {
                echo ' data-app-tooltip="' . htmlspecialchars((string) $item['tooltip'], ENT_QUOTES, 'UTF-8') . '"';
            }
            if (!empty($item['target'])) {
                echo ' target="' . htmlspecialchars((string) $item['target'], ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer"';
            }
            echo '>';
            echo '<span class="admin-nav-icon" aria-hidden="true"><i class="fa-solid ' . htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') . '"></i></span>';
            echo '<span class="admin-nav-text">' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span>';
            if ($badge > 0) {
                echo '<span class="badge text-bg-danger rounded-pill admin-nav-badge">' . ($badge > 99 ? '99+' : (string) $badge) . '</span>';
            }
            echo '</a></li>';
        }
        echo '</ul></div>';
    }
}

/**
 * External link to the WPU E-Faculty Workload system (separate app under htdocs).
 *
 * @return array{file:string,href:string,icon:string,label:string,tooltip:string,target:string}
 */
function nav_faculty_workload_external_item(): array
{
    return [
        'file' => 'faculty_workload_external',
        'href' => '/FACULTY%20WORKLOAD/public/login.php',
        'icon' => 'fa-briefcase',
        'label' => 'E-Faculty Workload',
        'tooltip' => 'Opens the WPU E-Faculty Workload system in a new tab to submit and track official workload forms.',
        'target' => '_blank',
    ];
}

/**
 * Short label under the app name in the sidebar (per role).
 */
function app_sidebar_brand_meta(string $role): string
{
    return match ($role) {
        'super_admin' => 'Super administrator',
        'admin' => 'System administration',
        'dean' => 'College dean',
        'program_chair' => 'Program chair',
        'gened' => 'General education',
        'faculty' => 'Faculty',
        'student' => 'Student',
        default => 'Signed in',
    };
}

/**
 * Font Awesome icon class for the top bar avatar (no "fa-solid" prefix).
 */
function app_sidebar_topbar_icon_class(string $role): string
{
    return match ($role) {
        'super_admin' => 'fa-user-secret',
        'admin' => 'fa-user-shield',
        'dean' => 'fa-user-tie',
        'program_chair' => 'fa-clipboard-list',
        'gened' => 'fa-graduation-cap',
        'faculty' => 'fa-chalkboard-user',
        'student' => 'fa-user-graduate',
        default => 'fa-circle-user',
    };
}

/**
 * Top bar user avatar: profile photo when set, otherwise role icon.
 */
function app_render_topbar_avatar(string $role, ?string $profilePhotoUrl): void
{
    if ($profilePhotoUrl !== null && $profilePhotoUrl !== '') {
        echo '<span class="admin-topbar-avatar rounded-circle d-inline-flex align-items-center justify-content-center overflow-hidden">';
        echo '<img src="' . htmlspecialchars($profilePhotoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" class="admin-topbar-avatar-img" width="34" height="34" loading="lazy" decoding="async">';
        echo '</span>';
        return;
    }

    $topbarIcon = app_sidebar_topbar_icon_class($role);
    echo '<span class="admin-topbar-avatar rounded-circle d-inline-flex align-items-center justify-content-center">';
    echo '<i class="fa-solid ' . htmlspecialchars($topbarIcon, ENT_QUOTES, 'UTF-8') . ' admin-topbar-avatar-icon" aria-hidden="true"></i>';
    echo '</span>';
}

/**
 * Renders grouped admin navigation (sidebar / mobile drawer).
 *
 * @param string $currentPage basename of current script, e.g. dashboard.php
 * @param int $messagingUnread unread message count for badge
 * @param bool $dismissOffcanvas when true, links close the mobile drawer (Bootstrap offcanvas)
 */
/**
 * Super Admin: account oversight, reports, and institution-wide weekly schedule view.
 *
 * @param string $currentPage basename of current script
 * @param int $messagingUnread unread message count (unused; kept for signature parity)
 * @param bool $dismissOffcanvas when true, links close the mobile drawer
 */
function render_super_admin_nav_sections(string $currentPage, int $messagingUnread, bool $dismissOffcanvas = false): void
{
    unset($messagingUnread);
    $sections = [
        'Overview' => [
            [
                'file' => 'dashboard.php',
                'href' => 'dashboard.php',
                'icon' => 'fa-gauge-high',
                'label' => 'Dashboard',
                'tooltip' => 'Returns to the Super Admin home with shortcuts to administrator account management.',
            ],
            [
                'file' => 'view_schedule.php',
                'href' => 'view_schedule.php',
                'icon' => 'fa-calendar-week',
                'label' => 'Weekly schedule',
                'tooltip' => 'Institution-wide week-at-a-glance schedule. Filter by college, program, term, faculty, or room.',
            ],
        ],
        'Accounts' => [
            [
                'file' => 'super_admin_accounts.php',
                'href' => 'super_admin_accounts.php',
                'icon' => 'fa-users-gear',
                'label' => 'Account monitor',
                'tooltip' => 'Monitor all logins, reset passwords, and create accounts for any role (including Super Administrators).',
            ],
            [
                'file' => 'super_admin_admins.php',
                'href' => 'super_admin_admins.php',
                'icon' => 'fa-user-shield',
                'label' => 'Administrator accounts',
                'tooltip' => 'Create or update System Administrator accounts (scheduling admins). Each account can show a title in the activity log.',
            ],
        ],
        'Academic' => [
            [
                'file' => 'super_admin_programs.php',
                'href' => 'super_admin_programs.php',
                'icon' => 'fa-graduation-cap',
                'label' => 'Program offers',
                'tooltip' => 'View, edit, or delete all program offers across every college in the university.',
            ],
        ],
        'Reports' => [
            [
                'file' => 'super_admin_faculty_inventory.php',
                'href' => 'super_admin_faculty_inventory.php',
                'icon' => 'fa-address-book',
                'label' => 'Faculty inventory',
                'tooltip' => 'All faculty records across colleges, including GE-tagged faculty. Filter by college, scope, or name.',
            ],
            [
                'file' => 'super_admin_teaching_load_report.php',
                'href' => 'super_admin_teaching_load_report.php',
                'icon' => 'fa-scale-balanced',
                'label' => 'Teaching load (under / over)',
                'tooltip' => 'All programs: weekly contact hours from schedules. Under load is below 18 hrs/week; overload is above 27. Filter by college and term.',
            ],
            nav_faculty_workload_external_item(),
            [
                'file' => 'admin_system_evaluations.php',
                'href' => 'admin_system_evaluations.php',
                'icon' => 'fa-clipboard-check',
                'label' => 'System evaluations',
                'tooltip' => 'View student and faculty feedback on portal usability, with averages by question and recent comments.',
            ],
        ],
    ];
    render_nav_sections_markup($sections, $currentPage, $dismissOffcanvas);
}

function render_admin_nav_sections(string $currentPage, int $messagingUnread, bool $dismissOffcanvas = false): void
{
    $sections = [
        'Overview' => [
            [
                'file' => 'dashboard.php', 'href' => 'dashboard.php', 'icon' => 'fa-gauge-high', 'label' => 'Dashboard',
                'tooltip' => 'Opens your administrator home with system-wide counts and shortcuts. Use this to return to the overview from any page.',
            ],
            [
                'file' => 'messages.php', 'href' => 'messages.php', 'icon' => 'fa-comments', 'label' => 'Messages', 'badge' => $messagingUnread,
                'tooltip' => 'Opens internal messages and class threads. Use this to coordinate with deans, faculty, and staff across colleges.',
            ],
            [
                'file' => 'schedule.php', 'href' => 'schedule.php', 'icon' => 'fa-list', 'label' => 'Schedules',
                'tooltip' => 'Opens the master schedule list. Use this to browse or drill into course times, rooms, and assignments.',
            ],
            [
                'file' => 'view_schedule.php', 'href' => 'view_schedule.php', 'icon' => 'fa-calendar-week', 'label' => 'Weekly view',
                'tooltip' => 'Shows schedules in a week-at-a-glance layout. Use this to spot patterns, gaps, or overlaps quickly.',
            ],
        ],
        'Academic' => [
            [
                'file' => 'admin_colleges.php', 'href' => 'admin_colleges.php', 'icon' => 'fa-building-columns', 'label' => 'Colleges',
                'tooltip' => 'Manage colleges, codes, and dean links. Use this when setting up structure or fixing college metadata.',
            ],
            [
                'file' => 'admin_deans.php', 'href' => 'admin_deans.php', 'icon' => 'fa-user-tie', 'label' => 'Deans',
                'tooltip' => 'Create or edit dean accounts tied to colleges. Use this to onboard leadership or reset access.',
            ],
            [
                'file' => 'admin_gened.php', 'href' => 'admin_gened.php', 'icon' => 'fa-graduation-cap', 'label' => 'GEN ED',
                'tooltip' => 'Configure General Education coordinators and scope. Use this for cross-college GE administration.',
            ],
            [
                'file' => 'admin_login_slideshow.php', 'href' => 'admin_login_slideshow.php', 'icon' => 'fa-images', 'label' => 'Login slideshow',
                'tooltip' => 'Upload and manage images that auto-scroll on the public login page.',
            ],
            [
                'file' => 'faculty_edutools.php', 'href' => 'faculty_edutools.php', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'EduTools',
                'tooltip' => 'Education sources: writing tools (Turnitin, Grammarly, QuillBot, AI detector), AI & research (ChatGPT, Perplexity, NotebookLM, Wolfram), notebooks, Khan Academy, and Notion—plus a built-in notebook.',
            ],
        ],
        'Management' => [
            [
                'file' => 'admin_requests.php', 'href' => 'admin_requests.php', 'icon' => 'fa-inbox', 'label' => 'Override requests',
                'tooltip' => 'Review scheduling override requests from faculty or chairs. Use this to approve or deny exceptions.',
            ],
            [
                'file' => 'conflicts.php', 'href' => 'conflicts.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Conflicts',
                'tooltip' => 'Lists scheduling conflicts and coordination items. Use this to resolve issues before publishing schedules.',
            ],
            [
                'file' => 'reports.php', 'href' => 'reports.php', 'icon' => 'fa-file-lines', 'label' => 'Reports',
                'tooltip' => 'Opens standard scheduling and workload reports. Use this for accreditation, planning, or audits.',
            ],
            [
                'file' => 'faculty_teaching_load.php', 'href' => 'faculty_teaching_load.php', 'icon' => 'fa-scale-balanced', 'label' => 'Teaching load (units)',
                'tooltip' => 'Faculty teaching load in units from scheduled offerings. Filter by college, term, or instructor.',
            ],
            nav_faculty_workload_external_item(),
            [
                'file' => 'global_reports.php', 'href' => 'global_reports.php', 'icon' => 'fa-chart-column', 'label' => 'Global reports',
                'tooltip' => 'Cross-college analytics and exports. Use this for institution-wide summaries beyond a single college.',
            ],
            [
                'file' => 'admin_system_evaluations.php',
                'href' => 'admin_system_evaluations.php',
                'icon' => 'fa-clipboard-check',
                'label' => 'System evaluations',
                'tooltip' => 'View student and faculty feedback on portal usability, with averages by question and recent comments.',
            ],
        ],
    ];
    render_nav_sections_markup($sections, $currentPage, $dismissOffcanvas);
}

/**
 * Grouped nav items for dean, program chair, GE, faculty, and student roles.
 *
 * @return array<string, list<array{file:string,href:string,icon:string,label:string,badge?:int}>>
 */
function role_nav_sections(string $role, int $messagingUnread): array
{
    $dash = [
        'file' => 'dashboard.php', 'href' => 'dashboard.php', 'icon' => 'fa-gauge-high', 'label' => 'Dashboard',
        'tooltip' => 'Opens your home dashboard with summary counts and shortcuts. Use this to return to the overview from any page.',
    ];
    $msg = [
        'file' => 'messages.php', 'href' => 'messages.php', 'icon' => 'fa-comments', 'label' => 'Messages', 'badge' => $messagingUnread,
        'tooltip' => 'Opens internal messages and class conversation threads. Use this to coordinate with colleagues and students.',
    ];
    $sched = [
        'file' => 'schedule.php', 'href' => 'schedule.php', 'icon' => 'fa-list', 'label' => 'Schedules',
        'tooltip' => 'Opens the schedule list for your scope. Use this to review or edit when you manage or teach courses.',
    ];
    $week = [
        'file' => 'view_schedule.php', 'href' => 'view_schedule.php', 'icon' => 'fa-calendar-week', 'label' => 'Weekly view',
        'tooltip' => 'Shows the schedule in a weekly layout. Use this to see how classes spread across days.',
    ];
    $facultyWorkload = nav_faculty_workload_external_item();

    return match ($role) {
        'dean' => [
            'Overview' => [$dash, $msg, $sched, $week],
            'Academic' => [
                [
                    'file' => 'faculty.php', 'href' => 'faculty.php', 'icon' => 'fa-chalkboard-user', 'label' => 'Faculty',
                    'tooltip' => 'Manage faculty records for your college. Use this to add people, link accounts, or fix assignments.',
                ],
                [
                    'file' => 'program_chairs.php', 'href' => 'program_chairs.php', 'icon' => 'fa-user-tie', 'label' => 'Program chairs',
                    'tooltip' => 'Assign or edit program chairs for programs in your college. Use this to delegate scheduling within departments.',
                ],
                [
                    'file' => 'dean_programs.php', 'href' => 'dean_programs.php', 'icon' => 'fa-graduation-cap', 'label' => 'Programs',
                    'tooltip' => 'Maintain program names and status under your college. Use this when curricula or program codes change.',
                ],
                [
                    'file' => 'dean_student_registrations.php',
                    'href' => 'dean_student_registrations.php',
                    'icon' => 'fa-user-check',
                    'label' => 'Registration approvals',
                    'tooltip' => 'Review and approve student self-registration requests for any program in your college.',
                ],
                [
                    'file' => 'courses.php', 'href' => 'courses.php', 'icon' => 'fa-book', 'label' => 'Courses',
                    'tooltip' => 'Create and edit courses your college offers. Use this before building schedules each term.',
                ],
                [
                    'file' => 'rooms.php', 'href' => 'rooms.php', 'icon' => 'fa-door-open', 'label' => 'Rooms',
                    'tooltip' => 'Manage physical or virtual rooms for scheduling. Use this so auto-schedule and manual edits use correct capacity.',
                ],
                [
                    'file' => 'faculty_classrooms.php',
                    'href' => 'faculty_classrooms.php',
                    'icon' => 'fa-chalkboard',
                    'label' => 'My classrooms',
                    'tooltip' => 'Create and manage online classrooms for your own teaching load.',
                ],
                [
                    'file' => 'faculty_offline.php',
                    'href' => 'faculty_offline.php',
                    'icon' => 'fa-cloud-arrow-up',
                    'label' => 'Offline uploads',
                    'tooltip' => 'Queue course posts and file uploads while offline, then sync them automatically when you reconnect.',
                ],
                [
                    'file' => 'faculty_student_list.php',
                    'href' => 'faculty_student_list.php',
                    'icon' => 'fa-users',
                    'label' => 'Student list',
                    'tooltip' => 'Lists your courses and registered students with present and absent attendance totals.',
                ],
                [
                    'file' => 'faculty_schedule.php', 'href' => 'faculty_schedule.php', 'icon' => 'fa-calendar-check', 'label' => 'My schedule',
                    'tooltip' => 'Shows your teaching timetable and online links. Use this to confirm times, rooms, and live session links.',
                ],
            ],
            'Scheduling' => [
                [
                    'file' => 'add_schedule.php', 'href' => 'add_schedule.php', 'icon' => 'fa-plus', 'label' => 'Add schedule',
                    'tooltip' => 'Create a new scheduled section manually. Use this for one-off classes or fine-tuning after auto-schedule.',
                ],
                [
                    'file' => 'auto_schedule.php', 'href' => 'auto_schedule.php', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'Auto schedule',
                    'tooltip' => 'Runs automated placement of courses into rooms and times. Use this to generate a draft or refresh a term.',
                ],
            ],
            'Management' => [
                [
                    'file' => 'reports.php', 'href' => 'reports.php', 'icon' => 'fa-file-lines', 'label' => 'Reports',
                    'tooltip' => 'College-level scheduling and workload reports. Use this for reviews, accreditation, or planning.',
                ],
                [
                    'file' => 'faculty_teaching_load.php', 'href' => 'faculty_teaching_load.php', 'icon' => 'fa-scale-balanced', 'label' => 'Teaching load (units)',
                    'tooltip' => 'Units per faculty from schedules in your college. Filter by department and term.',
                ],
                $facultyWorkload,
                [
                    'file' => 'conflicts.php', 'href' => 'conflicts.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Conflicts',
                    'tooltip' => 'Review room, faculty, or change-request conflicts. Use this to clear issues before finalizing schedules.',
                ],
            ],
        ],
        'program_chair' => [
            'Overview' => [$dash, $msg, $sched, $week],
            'Academic' => [
                ['file' => 'faculty.php', 'href' => 'faculty.php', 'icon' => 'fa-chalkboard-user', 'label' => 'Faculty'],
                ['file' => 'program_chair_students.php', 'href' => 'program_chair_students.php', 'icon' => 'fa-user-graduate', 'label' => 'Students'],
                [
                    'file' => 'program_chair_student_registrations.php',
                    'href' => 'program_chair_student_registrations.php',
                    'icon' => 'fa-user-check',
                    'label' => 'Registration approvals',
                    'tooltip' => 'Review and approve student self-registration requests for your program.',
                ],
                ['file' => 'courses.php', 'href' => 'courses.php', 'icon' => 'fa-book', 'label' => 'Courses'],
                [
                    'file' => 'faculty_classrooms.php',
                    'href' => 'faculty_classrooms.php',
                    'icon' => 'fa-chalkboard',
                    'label' => 'My classrooms',
                    'tooltip' => 'Create and manage online classrooms for your own teaching load.',
                ],
                [
                    'file' => 'faculty_offline.php',
                    'href' => 'faculty_offline.php',
                    'icon' => 'fa-cloud-arrow-up',
                    'label' => 'Offline uploads',
                    'tooltip' => 'Queue course posts and file uploads while offline, then sync them automatically when you reconnect.',
                ],
                [
                    'file' => 'faculty_student_list.php',
                    'href' => 'faculty_student_list.php',
                    'icon' => 'fa-users',
                    'label' => 'Student list',
                    'tooltip' => 'Lists your courses and registered students with present and absent attendance totals.',
                ],
                [
                    'file' => 'faculty_schedule.php', 'href' => 'faculty_schedule.php', 'icon' => 'fa-calendar-check', 'label' => 'My schedule',
                    'tooltip' => 'Shows your teaching timetable and online links. Use this to confirm times, rooms, and live session links.',
                ],
                [
                    'file' => 'faculty_edutools.php', 'href' => 'faculty_edutools.php', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'EduTools',
                    'tooltip' => 'Education sources: writing tools (Turnitin, Grammarly, QuillBot, AI detector), AI & research (ChatGPT, Perplexity, NotebookLM, Wolfram), notebooks, Khan Academy, and Notion—plus a built-in notebook.',
                ],
            ],
            'Scheduling' => [
                ['file' => 'add_schedule.php', 'href' => 'add_schedule.php', 'icon' => 'fa-plus', 'label' => 'Add schedule'],
            ],
            'Management' => [
                ['file' => 'reports.php', 'href' => 'reports.php', 'icon' => 'fa-file-lines', 'label' => 'Reports'],
                [
                    'file' => 'faculty_teaching_load.php', 'href' => 'faculty_teaching_load.php', 'icon' => 'fa-scale-balanced', 'label' => 'Teaching load (units)',
                    'tooltip' => 'Units per faculty for your program scheduled offerings. Filter by term or search faculty.',
                ],
                $facultyWorkload,
                ['file' => 'conflicts.php', 'href' => 'conflicts.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Conflicts'],
            ],
        ],
        'gened' => [
            'Overview' => [$dash, $msg, $sched, $week],
            'Academic' => [
                [
                    'file' => 'gened_schedule.php', 'href' => 'gened_schedule.php', 'icon' => 'fa-calendar-plus', 'label' => 'GE schedule',
                    'tooltip' => 'Build and edit General Education schedule rows. Use this to place GE sections in time and rooms.',
                ],
                [
                    'file' => 'gened_faculty.php', 'href' => 'gened_faculty.php', 'icon' => 'fa-chalkboard-user', 'label' => 'GE faculty',
                    'tooltip' => 'Manage which instructors teach GE and their GE flags. Use this before assigning GE courses.',
                ],
                [
                    'file' => 'gened_courses.php', 'href' => 'gened_courses.php', 'icon' => 'fa-book', 'label' => 'GE courses',
                    'tooltip' => 'Maintain the GE course catalog. Use this when codes, titles, or GE status change.',
                ],
                [
                    'file' => 'gened_rooms.php', 'href' => 'gened_rooms.php', 'icon' => 'fa-door-open', 'label' => 'GE rooms',
                    'tooltip' => 'Rooms available for GE scheduling. Use this so GE placements respect capacity and type.',
                ],
                [
                    'file' => 'gened_assignments.php', 'href' => 'gened_assignments.php', 'icon' => 'fa-building-user', 'label' => 'GE offerings',
                    'tooltip' => 'Map GE sections to colleges or cohorts. Use this to coordinate cross-college GE delivery.',
                ],
                [
                    'file' => 'faculty_classrooms.php',
                    'href' => 'faculty_classrooms.php',
                    'icon' => 'fa-chalkboard',
                    'label' => 'My classrooms',
                    'tooltip' => 'Create and manage online classrooms for your own teaching load.',
                ],
                [
                    'file' => 'faculty_offline.php',
                    'href' => 'faculty_offline.php',
                    'icon' => 'fa-cloud-arrow-up',
                    'label' => 'Offline uploads',
                    'tooltip' => 'Queue course posts and file uploads while offline, then sync them automatically when you reconnect.',
                ],
                [
                    'file' => 'faculty_student_list.php',
                    'href' => 'faculty_student_list.php',
                    'icon' => 'fa-users',
                    'label' => 'Student list',
                    'tooltip' => 'Lists your courses and registered students with present and absent attendance totals.',
                ],
                [
                    'file' => 'faculty_edutools.php', 'href' => 'faculty_edutools.php', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'EduTools',
                    'tooltip' => 'Education sources: writing tools (Turnitin, Grammarly, QuillBot, AI detector), AI & research (ChatGPT, Perplexity, NotebookLM, Wolfram), notebooks, Khan Academy, and Notion—plus a built-in notebook.',
                ],
            ],
            'Management' => [
                [
                    'file' => 'reports.php', 'href' => 'reports.php', 'icon' => 'fa-file-lines', 'label' => 'Reports',
                    'tooltip' => 'GE-focused scheduling reports. Use this to audit load, utilization, or compliance.',
                ],
                [
                    'file' => 'faculty_teaching_load.php', 'href' => 'faculty_teaching_load.php', 'icon' => 'fa-scale-balanced', 'label' => 'Teaching load (units)',
                    'tooltip' => 'GE faculty load in units from scheduled GE offerings. Filter by term or department.',
                ],
                $facultyWorkload,
                [
                    'file' => 'conflicts.php', 'href' => 'conflicts.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Conflicts',
                    'tooltip' => 'Review conflicts affecting GE sections. Use this to resolve overlaps before publishing.',
                ],
            ],
        ],
        'faculty' => [
            'Overview' => [$dash, $msg, $sched, $week],
            'Academic' => [
                [
                    'file' => 'faculty_classrooms.php', 'href' => 'faculty_classrooms.php', 'icon' => 'fa-chalkboard', 'label' => 'My classrooms',
                    'tooltip' => 'Opens your online class spaces for assigned courses. Use this to post content, run Meet, and track students.',
                ],
                [
                    'file' => 'faculty_offline.php',
                    'href' => 'faculty_offline.php',
                    'icon' => 'fa-cloud-arrow-up',
                    'label' => 'Offline uploads',
                    'tooltip' => 'Queue course posts and file uploads while offline, then sync them automatically when you reconnect.',
                ],
                [
                    'file' => 'faculty_student_list.php', 'href' => 'faculty_student_list.php', 'icon' => 'fa-users', 'label' => 'Student list',
                    'tooltip' => 'Lists your courses and registered students with present and absent attendance totals.',
                ],
                [
                    'file' => 'faculty_schedule.php', 'href' => 'faculty_schedule.php', 'icon' => 'fa-calendar-check', 'label' => 'My schedule',
                    'tooltip' => 'Shows your teaching timetable and online links. Use this to confirm times, rooms, and live session links.',
                ],
                [
                    'file' => 'faculty_edutools.php', 'href' => 'faculty_edutools.php', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'EduTools',
                    'tooltip' => 'Education sources: writing tools (Turnitin, Grammarly, QuillBot, AI detector), AI & research (ChatGPT, Perplexity, NotebookLM, Wolfram), notebooks, Khan Academy, and Notion—plus a built-in notebook.',
                ],
                [
                    'file' => 'faculty_system_evaluation.php',
                    'href' => 'faculty_system_evaluation.php',
                    'icon' => 'fa-clipboard-check',
                    'label' => 'System evaluation',
                    'tooltip' => 'Rate how well the portal supports your teaching (classrooms, grading, schedules). One response per school year; you can update it.',
                ],
            ],
            'Management' => [
                [
                    'file' => 'reports.php', 'href' => 'reports.php', 'icon' => 'fa-file-lines', 'label' => 'Reports',
                    'tooltip' => 'Reports available to faculty (e.g. workload). Use this when you need a printable or official summary.',
                ],
                [
                    'file' => 'faculty_teaching_load.php', 'href' => 'faculty_teaching_load.php', 'icon' => 'fa-scale-balanced', 'label' => 'Teaching load (units)',
                    'tooltip' => 'Your personal teaching load in units from your scheduled offerings. Filter by term.',
                ],
                $facultyWorkload,
                [
                    'file' => 'conflicts.php', 'href' => 'conflicts.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Conflicts',
                    'tooltip' => 'Items that need coordination with the office. Use this to see if your classes have pending issues.',
                ],
            ],
        ],
        'student' => [
            'Overview' => [
                array_merge($dash, [
                    'tooltip' => 'Opens your home dashboard with summary counts and shortcuts. Use this to return to the overview after visiting Messages or My Classes.',
                ]),
                array_merge($msg, [
                    'tooltip' => 'Opens class conversations for your enrolled subjects. Use this to read and send messages shared with your instructor and classmates.',
                ]),
            ],
            'Academic' => [
                [
                    'file' => 'student_classrooms.php',
                    'href' => 'student_classrooms.php',
                    'icon' => 'fa-user-graduate',
                    'label' => 'My classes',
                    'tooltip' => 'Lists every online class you are enrolled in. Use this to open a class, join Meet, or enter a join code from your instructor.',
                ],
                [
                    'file' => 'student_offline.php',
                    'href' => 'student_offline.php',
                    'icon' => 'fa-cloud-arrow-down',
                    'label' => 'Offline reading',
                    'tooltip' => 'Read saved faculty announcements and course materials without internet. Save while online first, then open this page offline.',
                ],
                [
                    'file' => 'student_edutools.php',
                    'href' => 'student_edutools.php',
                    'icon' => 'fa-wand-magic-sparkles',
                    'label' => 'Learning tools',
                    'tooltip' => 'Opens EduTools: built-in notebook, NotebookLM, ChatGPT, Perplexity, Khan Academy, Wolfram Alpha, and Notion for studying and research.',
                ],
                [
                    'file' => 'student_materials_reviewer.php',
                    'href' => 'student_materials_reviewer.php',
                    'icon' => 'fa-clipboard-question',
                    'label' => 'Materials reviewer',
                    'tooltip' => 'Review course materials week by week and generate automatic practice questions from what your instructor posted for each week.',
                ],
                [
                    'file' => 'student_calculator.php',
                    'href' => 'student_calculator.php',
                    'icon' => 'fa-calculator',
                    'label' => 'Scientific calculator',
                    'tooltip' => 'A full-featured scientific calculator with trigonometry, logarithms, powers, roots, and more—right inside the portal.',
                ],
                [
                    'file' => 'student_wellness.php',
                    'href' => 'student_wellness.php',
                    'icon' => 'fa-heart-pulse',
                    'label' => 'Wellness companion',
                    'tooltip' => 'Private mental health support chat (stress, anxiety, loneliness). English/Tagalog. Not therapy or emergency care—crisis hotlines shown when needed.',
                ],
                [
                    'file' => 'student_system_evaluation.php',
                    'href' => 'student_system_evaluation.php',
                    'icon' => 'fa-clipboard-check',
                    'label' => 'System evaluation',
                    'tooltip' => 'Rate how well the portal supports your learning (classes, tools, reliability). One response per school year; you can update it.',
                ],
            ],
        ],
        default => [],
    };
}

/**
 * Renders role-based grouped navigation (non-admin users).
 */
function render_role_nav_sections(string $role, string $currentPage, int $messagingUnread, bool $dismissOffcanvas = false): void
{
    $sections = role_nav_sections($role, $messagingUnread);
    if ($sections === []) {
        return;
    }
    render_nav_sections_markup($sections, $currentPage, $dismissOffcanvas);
}
