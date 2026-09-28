<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/db.php';

function parsePgArray($pgArray) {
    if (!$pgArray || $pgArray === '{}') return [];
    $clean = trim($pgArray, '{}');
    if ($clean === '') return [];
    return str_getcsv($clean, ',', '"');
}

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requestData() {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

$resource = $_GET['resource'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($resource === 'enrolled_courses') {
            $studentId = $_GET['studentId'] ?? '';
            if (!$studentId) respond(['success' => false, 'message' => 'Student ID required'], 400);

            $sql = "SELECT DISTINCT sub.subject_code AS \"courseCode\", sub.subject_name AS \"courseName\",
                           COALESCE(s_schedule.section_name, s_offering.section_name) AS section,
                           sc.day_of_the_week AS day,
                           CONCAT(sc.start_time, ' - ', sc.end_time) AS \"timeRange\",
                           CONCAT_WS(', ', NULLIF(TRIM(r.building), ''), NULLIF(TRIM(r.room_num), '')) AS room,
                           i.instructor_name AS instructor,
                           o.class_type AS type,
                           o.offering_id
                    FROM plotting p
                    JOIN offering o ON o.offering_id = p.offering_id
                    JOIN subject sub ON sub.subject_id = o.subject_id
                    LEFT JOIN schedule sc ON sc.schedule_id = o.schedule_id
                    LEFT JOIN section s_schedule ON s_schedule.section_id = sc.section_id
                    LEFT JOIN section s_offering ON s_offering.section_id = o.section_id
                    LEFT JOIN instructor i ON i.instructor_id = o.instructor_id
                    LEFT JOIN room r ON r.room_id = o.room_id
                    WHERE TRIM(p.student_id) = TRIM(:studentId)
                    ORDER BY 5";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':studentId' => $studentId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                 $row['offeringIds'] = [(int)$row['offering_id']];
            }
            respond($rows);
        }
        respond(['success' => false, 'message' => 'Unknown GET resource'], 404);
    }

    $data = requestData();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'POST required'], 405);
    }

    if ($resource === 'enroll') {
        $studentId = $data['studentId'] ?? '';
        $offeringIds = $data['offeringIds'] ?? [];
        if (!$studentId || empty($offeringIds)) {
            respond(['success' => false, 'message' => 'Student ID and Offering IDs are required'], 400);
        }

        $conn->beginTransaction();
        try {
            $deleteStmt = $conn->prepare("DELETE FROM plotting WHERE student_id = :studentId AND offering_id = :offeringId");
            $stmt = $conn->prepare("INSERT INTO plotting (student_id, offering_id, status) VALUES (:studentId, :offeringId, 'Enrolled')");
            foreach ($offeringIds as $offeringId) {
                $deleteStmt->execute([':studentId' => $studentId, ':offeringId' => $offeringId]);
                $stmt->execute([':studentId' => $studentId, ':offeringId' => $offeringId]);
            }
            $conn->commit();
            respond(['success' => true]);
        } catch (Exception $e) {
            $conn->rollBack();
            respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    if ($resource === 'unenroll') {
        $studentId = $data['studentId'] ?? '';
        $offeringIds = $data['offeringIds'] ?? [];
        if (!$studentId || empty($offeringIds)) {
            respond(['success' => false, 'message' => 'Student ID and Offering IDs are required'], 400);
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("DELETE FROM plotting WHERE student_id = :studentId AND offering_id = :offeringId");
            foreach ($offeringIds as $offeringId) {
                $stmt->execute([':studentId' => $studentId, ':offeringId' => $offeringId]);
            }
            $conn->commit();
            respond(['success' => true]);
        } catch (Exception $e) {
            $conn->rollBack();
            respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    respond(['success' => false, 'message' => 'Unknown POST resource'], 404);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    respond(['success' => false, 'message' => $e->getMessage()], 500);
}
?>