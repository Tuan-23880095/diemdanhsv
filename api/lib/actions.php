<?php
declare(strict_types=1);

/**
 * api/lib/actions.php — Router 13 action cũ (gas/05-Api.gs) theo đúng bảng
 * đối chiếu docs/04-API-PHP.md mục 5.
 *
 * GĐ2 (PLAN) triển khai `ping`. GĐ3 thêm `login`/`logout`/`requestGradeCode`/
 * `verifyGradeCode` (api/lib/auth.php, api/lib/gradeauth.php). GĐ4 thêm
 * `openAttendance`/`closeAttendance`/`checkin`/`liveRoster`
 * (api/lib/attendance.php, api/lib/roles.php). GĐ5 thêm 4 action ĐỌC cuối
 * cùng — `studentHistory`, `myGrades`, `listClasses`, `listSessions`
 * (api/lib/queries.php) — đủ 13/13 action cũ, không còn nhánh "chưa triển
 * khai" nào trong router này.
 */

const API_VERSION = 'php-0.7';

/** action => 'GET' | 'POST', đúng danh sách 13 action + phương thức cũ. */
const KNOWN_ACTIONS = [
    'ping'             => 'GET',
    'studentHistory'   => 'GET',
    'myGrades'         => 'GET',
    'liveRoster'       => 'GET',
    'listClasses'      => 'GET',
    'listSessions'     => 'GET',
    'login'            => 'POST',
    'logout'           => 'POST',
    'requestGradeCode' => 'POST',
    'verifyGradeCode'  => 'POST',
    'openAttendance'   => 'POST',
    'closeAttendance'  => 'POST',
    'checkin'          => 'POST',

    // GĐ7 — quản trị web tối thiểu (api/lib/admin.php, docs/04-API-PHP.md mục 16).
    // Action MỚI, ngoài 13 action cũ; cùng khuôn phong bì, đều cần token.
    'adminListCourses'   => 'GET',
    'adminListLecturers' => 'GET',
    'adminListClasses'   => 'GET',
    'adminListRoster'    => 'GET',
    'adminListSessions'  => 'GET',
    'adminSaveCourse'    => 'POST',
    'adminSaveClass'     => 'POST',
    'adminSaveSession'   => 'POST',
    'adminSaveStudent'   => 'POST',
    'adminEnroll'        => 'POST',
    'adminUnenroll'      => 'POST',
    'adminImportRoster'  => 'POST',

    // GĐ8 — chuyên cần, nhập điểm CSV, điểm danh tay (api/lib/grading.php, docs/04 mục 17).
    'adminAttendanceReport'     => 'GET',
    'adminGradesReport'         => 'GET',
    'adminSessionAttendance'    => 'GET',
    'adminApplyAttendanceScore' => 'POST',
    'adminImportGrades'         => 'POST',
    'adminSetAttendance'        => 'POST',

    // GĐ11 — Phiếu học tập online thực tập KHTĐ (api/lib/khtd.php, docs/04 mục 26).
    'khtdLogin'              => 'POST',
    'khtdListWorksheets'     => 'GET',
    'khtdGetWorksheet'       => 'GET',
    'khtdSaveDraft'          => 'POST',
    'khtdSubmit'             => 'POST',
    'khtdSeedWorksheets'     => 'POST',
    'khtdSetWorksheetStatus' => 'POST',
    'khtdLecturerList'       => 'GET',
    'khtdLecturerSubmission' => 'GET',
    'khtdLecturerGrade'      => 'POST',
    'khtdExportCsv'          => 'GET',
    'khtdRegrade'            => 'POST',
    'khtdAiTest'             => 'GET',
    'khtdActiveCode'         => 'GET',
    'khtdRetake'             => 'POST',
    'khtdSetWorksheetPolicy' => 'POST',
    'khtdGrantRetake'        => 'POST',
    'khtdUploadPhoto'        => 'POST',
    'khtdLogEvent'           => 'POST',   // giám sát chống gian lận (docs/04 mục 39)
    'khtdGsUnlock'           => 'POST',
    'khtdReviewCode'         => 'POST',   // ôn tập ở nhà (docs/04 mục 40)
    'khtdReviewLogin'        => 'POST',
    'khtdStats'              => 'GET',
    // Phiếu luyện thi cuối khóa (api/lib/khtd_exam.php, docs/04 mục 33)
    'khtdStartExam'          => 'POST',
    'khtdSetExamKey'         => 'POST',
    'khtdPracticeBoard'      => 'GET',
    // GĐ12 — lưu trữ cuối học kỳ (api/lib/archive.php, docs/04 mục 32)
    'archivePreview'         => 'GET',
    'archiveList'            => 'GET',
    'archiveReset'           => 'POST',
    // Phòng tương tác trang trình chiếu dkv/ (api/lib/liveroom.php, docs/04 mục 36).
    // roomPoll dùng POST vì mang hostKey (bí mật không đi qua GET/URL).
    'roomOpen'               => 'POST',
    'roomInfo'               => 'GET',
    'roomSubmit'             => 'POST',
    'roomPoll'               => 'POST',
    'roomSetLesson'          => 'POST',
    'roomClose'              => 'POST',
];

/** Điều hướng theo $action, gọi thẳng api_ok()/api_fail() (hai hàm này tự exit). */
function api_dispatch(string $action, string $method, array $params): void
{
    if ($action === '') {
        // Nguyên văn gas/05-Api.gs dòng 73.
        api_fail('Thiếu tham số action.');
        return;
    }

    if (!array_key_exists($action, KNOWN_ACTIONS)) {
        // Nguyên văn gas/05-Api.gs dòng 76 — không đổi để APIService.js không
        // phải sửa (rule 5).
        api_fail('Action không hợp lệ: ' . $action);
        return;
    }

    $expectedMethod = KNOWN_ACTIONS[$action];
    if ($method !== $expectedMethod) {
        api_fail('Action "' . $action . '" phải gọi bằng ' . $expectedMethod . '.');
        return;
    }

    switch ($action) {
        case 'ping':
            action_ping();
            return;

        case 'login':
            action_login($params);
            return;

        case 'logout':
            action_logout($params);
            return;

        case 'requestGradeCode':
            action_request_grade_code($params);
            return;

        case 'verifyGradeCode':
            action_verify_grade_code($params);
            return;

        case 'openAttendance':
            action_open_attendance($params);
            return;

        case 'closeAttendance':
            action_close_attendance($params);
            return;

        case 'checkin':
            action_checkin($params);
            return;

        case 'liveRoster':
            action_live_roster($params);
            return;

        case 'studentHistory':
            action_student_history($params);
            return;

        case 'myGrades':
            action_my_grades($params);
            return;

        case 'listClasses':
            action_list_classes($params);
            return;

        case 'listSessions':
            action_list_sessions($params);
            return;

        case 'adminListCourses':   action_admin_list_courses($params);   return;
        case 'adminListLecturers': action_admin_list_lecturers($params); return;
        case 'adminListClasses':   action_admin_list_classes($params);   return;
        case 'adminListRoster':    action_admin_list_roster($params);    return;
        case 'adminListSessions':  action_admin_list_sessions($params);  return;
        case 'adminSaveCourse':    action_admin_save_course($params);    return;
        case 'adminSaveClass':     action_admin_save_class($params);     return;
        case 'adminSaveSession':   action_admin_save_session($params);   return;
        case 'adminSaveStudent':   action_admin_save_student($params);   return;
        case 'adminEnroll':        action_admin_enroll($params);         return;
        case 'adminUnenroll':      action_admin_unenroll($params);       return;
        case 'adminImportRoster':  action_admin_import_roster($params);  return;

        case 'adminAttendanceReport':     action_admin_attendance_report($params);      return;
        case 'adminGradesReport':         action_admin_grades_report($params);          return;
        case 'adminSessionAttendance':    action_admin_session_attendance($params);     return;
        case 'adminApplyAttendanceScore': action_admin_apply_attendance_score($params); return;
        case 'adminImportGrades':         action_admin_import_grades($params);          return;
        case 'adminSetAttendance':        action_admin_set_attendance($params);         return;

        case 'khtdLogin':              action_khtd_login($params);                return;
        case 'khtdListWorksheets':     action_khtd_list_worksheets($params);      return;
        case 'khtdGetWorksheet':       action_khtd_get_worksheet($params);        return;
        case 'khtdSaveDraft':          action_khtd_save_draft($params);           return;
        case 'khtdSubmit':             action_khtd_submit($params);               return;
        case 'khtdSeedWorksheets':     action_khtd_seed_worksheets($params);      return;
        case 'khtdSetWorksheetStatus': action_khtd_set_worksheet_status($params); return;
        case 'khtdLecturerList':       action_khtd_lecturer_list($params);        return;
        case 'khtdLecturerSubmission': action_khtd_lecturer_submission($params);  return;
        case 'khtdLecturerGrade':      action_khtd_lecturer_grade($params);       return;
        case 'khtdExportCsv':          action_khtd_export_csv($params);           return;
        case 'khtdRegrade':            action_khtd_regrade($params);              return;
        case 'khtdAiTest':             action_khtd_ai_test($params);              return;
        case 'khtdActiveCode':         action_khtd_active_code($params);          return;
        case 'khtdRetake':             action_khtd_retake($params);               return;
        case 'khtdSetWorksheetPolicy': action_khtd_set_worksheet_policy($params); return;
        case 'khtdLogEvent':           action_khtd_log_event($params);            return;
        case 'khtdGsUnlock':           action_khtd_gs_unlock($params);            return;
        case 'khtdReviewCode':         action_khtd_review_code($params);          return;
        case 'khtdReviewLogin':        action_khtd_review_login($params);         return;
        case 'khtdGrantRetake':        action_khtd_grant_retake($params);         return;
        case 'khtdUploadPhoto':        action_khtd_upload_photo($params);         return;
        case 'khtdStats':              action_khtd_stats($params);                return;
        case 'khtdStartExam':          action_khtd_start_exam($params);           return;
        case 'khtdSetExamKey':         action_khtd_set_exam_key($params);         return;
        case 'khtdPracticeBoard':      action_khtd_practice_board($params);       return;
        case 'archivePreview':         action_archive_preview($params);           return;
        case 'archiveList':            action_archive_list($params);              return;
        case 'archiveReset':           action_archive_reset($params);             return;
        case 'roomOpen':               action_room_open($params);                 return;
        case 'roomInfo':               action_room_info($params);                 return;
        case 'roomSubmit':             action_room_submit($params);               return;
        case 'roomPoll':               action_room_poll($params);                 return;
        case 'roomSetLesson':          action_room_set_lesson($params);           return;
        case 'roomClose':              action_room_close($params);                return;
    }
}

/**
 * GET ?action=ping — không đụng CSDL/config bí mật, chỉ xác nhận bản deploy
 * đang chạy đã có code mới (cùng mục đích API_VERSION cũ, gas/05-Api.gs).
 */
function action_ping(): void
{
    api_ok([
        'time'    => date("Y-m-d\TH:i:s"),
        'version' => API_VERSION,
    ]);
}
