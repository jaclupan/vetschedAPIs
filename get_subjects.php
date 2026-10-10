<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/db.php';

function parsePgArray($value) {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || strlen($value) < 2 || $value[0] !== '{' || substr($value, -1) !== '}') {
        return [];
    }

    $value = substr($value, 1, -1);
    if ($value === '') {
        return [];
    }

    return array_map(static function ($item) {
        return trim($item, '"');
    }, explode(',', $value));
}

$yearLevel = $_GET['year_level'] ?? $_POST['year_level'] ?? null;
$semester = $_GET['semester'] ?? $_POST['semester'] ?? null;
if ($yearLevel !== null && $yearLevel !== '') {
    $yearLevel = (int) $yearLevel;
}
if ($semester !== null && $semester !== '') {
    $semester = (int) $semester;
}

try {
    $subjectSql = "
        SELECT subject_id AS id,
               subject_code AS code,
               subject_name AS name,
               year_level AS \"yearLevel\",
               semester
        FROM subject
    ";

    $params = [];
    $filters = [];
    if ($yearLevel !== null) {
        $filters[] = 'year_level = :year_level';
        $params[':year_level'] = $yearLevel;
    }
    if ($semester !== null) {
        $filters[] = 'semester = :semester';
        $params[':semester'] = $semester;
    }
    if ($filters) {
        $subjectSql .= ' WHERE ' . implode(' AND ', $filters);
    }

    $subjectSql .= ' ORDER BY year_level, semester, subject_id';

    $subjectStmt = $conn->prepare($subjectSql);
    $subjectStmt->execute($params);
    $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

    $sectionsBySubject = [];
    if ($subjects) {
        $subjectIds = array_map(static fn(array $subject): int => (int) $subject['id'], $subjects);
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $sectionSql = "
            SELECT o.subject_id,
                   s.section_id AS id,
                   s.section_name AS \"type\",
                   MIN(sc.start_time) AS start,
                   MAX(sc.end_time) AS end,
                   ARRAY_AGG(DISTINCT sc.day_of_the_week ORDER BY sc.day_of_the_week)
                       FILTER (WHERE sc.day_of_the_week IS NOT NULL) AS days,
                   MAX(i.instructor_name) AS instructor,
                   MAX(CONCAT_WS(', ', NULLIF(TRIM(r.building), ''), NULLIF(TRIM(r.room_num), ''))) AS room,
                   s.max_capacity AS \"maxCapacity\",
                   MAX(o.class_type) AS \"classType\",
                   ARRAY_AGG(DISTINCT o.class_type) FILTER (WHERE o.class_type IS NOT NULL) AS \"classTypes\"
            FROM section s
            INNER JOIN schedule sc ON sc.section_id = s.section_id
            INNER JOIN offering o ON o.schedule_id = sc.schedule_id AND o.subject_id IN ($placeholders)
            LEFT JOIN instructor i ON i.instructor_id = o.instructor_id
            LEFT JOIN room r ON r.room_id = o.room_id
            WHERE s.is_open = TRUE
            GROUP BY o.subject_id, s.section_id, s.section_name, s.max_capacity
            ORDER BY s.section_id
        ";
        $sectionStmt = $conn->prepare($sectionSql);
        $sectionStmt->execute($subjectIds);
        foreach ($sectionStmt->fetchAll(PDO::FETCH_ASSOC) as $section) {
            $subjectId = (int) $section['subject_id'];
            unset($section['subject_id']);
            $section['days'] = parsePgArray($section['days']);
            $section['subjectId'] = $subjectId;
            $section['start'] = $section['start'] ?? '';
            $section['end'] = $section['end'] ?? '';
            $section['room'] = $section['room'] ?? '';
            $section['instructor'] = $section['instructor'] ?? '';
            $section['maxCapacity'] = $section['maxCapacity'] ?? 0;
            $section['classType'] = $section['classType'] ?? 'Lecture';
            $section['classTypes'] = parsePgArray($section['classTypes']);
            $sectionsBySubject[$subjectId][] = $section;
        }
    }

    $response = [];
    foreach ($subjects as $subject) {
        $subject['sections'] = $sectionsBySubject[(int) $subject['id']] ?? [];
        $response[] = $subject;
    }

    echo json_encode($response);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
?>
