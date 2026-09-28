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
    // Remove braces and split by comma
    $clean = trim($pgArray, '{}');
    if ($clean === '') return [];
    // Handle quoted strings in array if any, though likely simple days
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
        if ($resource === 'subjects') {
            $rows = $conn->query('SELECT subject_id AS id, TRIM(subject_code) AS code, subject_name AS name, year_level AS "yearLevel" FROM subject ORDER BY subject_id')->fetchAll(PDO::FETCH_ASSOC);
            respond($rows);
        }

        if ($resource === 'sections') {
            $sql = "SELECT s.section_id AS id, s.section_name AS type,
                           s.is_open AS \"isOpen\",
                           s.subject_id AS \"subjectId\",
                           s.max_capacity AS \"maxCapacity\",
                           (SELECT COUNT(DISTINCT student_id)
                            FROM plotting
                            WHERE offering_id IN (
                                SELECT offering_id FROM offering WHERE section_id = s.section_id
                                UNION
                                SELECT offering_id FROM offering WHERE schedule_id IN (SELECT schedule_id FROM schedule WHERE section_id = s.section_id)
                            )) AS \"enrollmentCount\"
                    FROM section s
                    ORDER BY s.section_id";
            $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                // 3. Get all slots for this section
                $classSql = "SELECT o.class_type AS \"classType\", sc.day_of_the_week AS day,
                                   sc.start_time AS start, sc.end_time AS \"end\",
                                   i.instructor_name AS instructor,
                                   CONCAT_WS(', ', NULLIF(TRIM(r.building), ''), NULLIF(TRIM(r.room_num), '')) AS room,
                                   o.offering_id
                            FROM schedule sc
                            JOIN offering o ON (o.schedule_id = sc.schedule_id OR o.section_id = sc.section_id)
                            LEFT JOIN instructor i ON i.instructor_id = o.instructor_id
                            LEFT JOIN room r ON r.room_id = o.room_id
                            WHERE sc.section_id = :section";
                $classStmt = $conn->prepare($classSql);
                $classStmt->execute([':section' => $row['id']]);
                $allSlots = $classStmt->fetchAll(PDO::FETCH_ASSOC);

                // Group slots into classes (same type, time, instructor, room)
                $classes = [];
                $allOfferingIds = [];
                foreach ($allSlots as $slot) {
                    $allOfferingIds[] = (int)$slot['offering_id'];
                    $key = ($slot['classType'] ?: 'Lecture') . '|' . $slot['start'] . '|' . $slot['end'] . '|' . $slot['instructor'] . '|' . $slot['room'];
                    if (!isset($classes[$key])) {
                        $classes[$key] = [
                            'classType' => $slot['classType'] ?: 'Lecture',
                            'start' => $slot['start'],
                            'end' => $slot['end'],
                            'days' => [$slot['day']],
                            'instructor' => $slot['instructor'],
                            'room' => $slot['room'],
                            'offeringIds' => [(int)$slot['offering_id']]
                        ];
                    } else {
                        if (!in_array($slot['day'], $classes[$key]['days'])) {
                            $classes[$key]['days'][] = $slot['day'];
                        }
                        if (!in_array((int)$slot['offering_id'], $classes[$key]['offeringIds'])) {
                            $classes[$key]['offeringIds'][] = (int)$slot['offering_id'];
                        }
                    }
                }

                $row['classes'] = array_values($classes);
                $row['offeringIds'] = array_values(array_unique($allOfferingIds));
                $row['id'] = (int)$row['id'];
                $row['subjectId'] = $row['subjectId'] !== null ? (int)$row['subjectId'] : null;
                $row['maxCapacity'] = (int)$row['maxCapacity'];
                $row['enrollmentCount'] = (int)$row['enrollmentCount'];
                $row['remainingSeats'] = max($row['maxCapacity'] - $row['enrollmentCount'], 0);
                $row['isOpen'] = (bool)$row['isOpen'];
            }
            respond($rows);
        }

        if ($resource === 'students') {
            $sql = "SELECT student_id AS id, student_id AS \"studentId\",
                           CONCAT_WS(' ', first_name, last_name) AS name
                    FROM account ORDER BY student_id";
            respond($conn->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($resource === 'enrolled_courses') {
            $studentId = $_GET['studentId'] ?? '';
            if (!$studentId) {
                respond(['success' => false, 'message' => 'Student ID required'], 400);
            }

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
                $row['section'] = $row['section'] ?: 'Unassigned';
                $row['day'] = $row['day'] ?: 'TBA';
                $row['timeRange'] = $row['timeRange'] ?: 'TBA';
                $row['room'] = $row['room'] ?: 'TBA';
                $row['instructor'] = $row['instructor'] ?: 'TBA';
                $row['type'] = $row['type'] ?: 'Lecture';
                $row['offeringIds'] = [(int) $row['offering_id']];
            }
            respond($rows);
        }

        respond(['success' => false, 'message' => 'Unknown resource'], 404);
    }

    $data = requestData();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'POST required'], 405);
    }

    if (($data['action'] ?? '') === 'delete') {
        $id = $data['id'] ?? null;
        if (!is_numeric($id)) {
            respond(['success' => false, 'message' => 'A valid ID is required'], 400);
        }

        $conn->beginTransaction();
        if ($resource === 'sections') {
            $stmt = $conn->prepare('DELETE FROM offering WHERE schedule_id IN (SELECT schedule_id FROM schedule WHERE section_id = :id)');
            $stmt->execute([':id' => $id]);
            $stmt = $conn->prepare('DELETE FROM schedule WHERE section_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $conn->prepare('DELETE FROM section WHERE section_id = :id');
            $stmt->execute([':id' => $id]);
        } elseif ($resource === 'subjects') {
            $stmt = $conn->prepare('DELETE FROM offering WHERE subject_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $conn->prepare('DELETE FROM section WHERE subject_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $conn->prepare('DELETE FROM subject WHERE subject_id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $conn->rollBack();
            respond(['success' => false, 'message' => 'Unknown delete resource'], 404);
        }
        $conn->commit();
        respond(['success' => true]);
    }

    if ($resource === 'subjects') {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $yearLevel = (int) ($data['yearLevel'] ?? 1);
        if ($code === '' || $name === '') {
            respond(['success' => false, 'message' => 'Subject code and name are required'], 400);
        }
        if ($yearLevel < 1 || $yearLevel > 4) {
            respond(['success' => false, 'message' => 'Year level must be between 1 and 4'], 400);
        }
        if (strlen($code) > 10) {
            respond(['success' => false, 'message' => 'Subject code must be 10 characters or fewer'], 400);
        }
        if (!empty($data['id']) && is_numeric($data['id'])) {
            $stmt = $conn->prepare('UPDATE subject SET subject_code = :code, subject_name = :name, year_level = :year_level WHERE subject_id = :id');
            $stmt->execute([':id' => $data['id'], ':code' => $code, ':name' => $name, ':year_level' => $yearLevel]);
            $id = (int) $data['id'];
        } else {
            $stmt = $conn->prepare('INSERT INTO subject (subject_code, subject_name, year_level) VALUES (:code, :name, :year_level) RETURNING subject_id');
            $stmt->execute([':code' => $code, ':name' => $name, ':year_level' => $yearLevel]);
            $id = (int) $stmt->fetchColumn();
        }
        respond(['success' => true, 'id' => $id, 'code' => $code, 'name' => $name, 'yearLevel' => $yearLevel]);
    }

    if ($resource === 'students' && ($data['action'] ?? '') === 'endSemester') {
        $conn->beginTransaction();
        $clearSchedules = $conn->prepare('DELETE FROM plotting');
        $clearSchedules->execute();
        $stmt = $conn->prepare('UPDATE account SET year_level = NULL');
        $stmt->execute();
        $conn->commit();
        respond(['success' => true, 'updated' => $stmt->rowCount()]);
    }

    if ($resource === 'sections' && array_key_exists('classes', $data)) {
        $subjectId = $data['subjectId'] ?? $data['subject_id'] ?? null;
        $sectionName = trim((string) ($data['type'] ?? ''));
        $capacity = (int) ($data['maxCapacity'] ?? 0);
        $classes = is_array($data['classes']) ? $data['classes'] : [];
        if (!is_numeric($subjectId) || $sectionName === '' || $capacity < 1) {
            respond(['success' => false, 'message' => 'Section name, subject, and capacity are required'], 400);
        }
        foreach ($classes as $classIndex => $class) {
            $classDays = is_array($class['days'] ?? null) ? $class['days'] : [];
            $classStart = (string) ($class['start'] ?? '');
            $classEnd = (string) ($class['end'] ?? '');
            if (count($classDays) !== 1) {
                respond(['success' => false, 'message' => 'Each class must have exactly one day'], 400);
            }
            foreach (array_slice($classes, $classIndex + 1) as $otherClass) {
                $otherDays = is_array($otherClass['days'] ?? null) ? $otherClass['days'] : [];
                $otherStart = (string) ($otherClass['start'] ?? '');
                $otherEnd = (string) ($otherClass['end'] ?? '');
                $sameDay = array_intersect($classDays, $otherDays);
                if ($sameDay && $classStart < $otherEnd && $otherStart < $classEnd) {
                    respond(['success' => false, 'message' => 'Schedule conflict: two classes in this section overlap on ' . implode(', ', $sameDay)], 409);
                }
            }
        }

        $conn->beginTransaction();
        $sectionId = null;
        if (!empty($data['id']) && is_numeric($data['id'])) {
            $sectionId = (int) $data['id'];
            $stmt = $conn->prepare('UPDATE section SET section_name = :name, subject_id = :subject, max_capacity = :capacity WHERE section_id = :id');
            $stmt->execute([':id' => $sectionId, ':name' => $sectionName, ':subject' => $subjectId, ':capacity' => $capacity]);
        } else {
            $stmt = $conn->prepare('SELECT section_id FROM section WHERE subject_id = :subject AND LOWER(TRIM(section_name)) = LOWER(TRIM(:name)) LIMIT 1');
            $stmt->execute([':subject' => $subjectId, ':name' => $sectionName]);
            $sectionId = (int) $stmt->fetchColumn();
            if (!$sectionId) {
                $stmt = $conn->prepare('INSERT INTO section (section_name, subject_id, max_capacity, is_open) VALUES (:name, :subject, :capacity, :is_open) RETURNING section_id');
                $stmt->bindValue(':name', $sectionName);
                $stmt->bindValue(':subject', (int) $subjectId, PDO::PARAM_INT);
                $stmt->bindValue(':capacity', $capacity, PDO::PARAM_INT);
                $stmt->bindValue(':is_open', (bool) ($data['isOpen'] ?? false), PDO::PARAM_BOOL);
                $stmt->execute();
                $sectionId = (int) $stmt->fetchColumn();
            }
        }

        $stmt = $conn->prepare('DELETE FROM offering WHERE offering_id IN (SELECT o.offering_id FROM offering o JOIN schedule sc ON sc.schedule_id = o.schedule_id WHERE sc.section_id = :section_schedule) OR section_id = :section_direct');
        $stmt->execute([':section_schedule' => $sectionId, ':section_direct' => $sectionId]);
        $stmt = $conn->prepare('DELETE FROM schedule WHERE section_id = :section');
        $stmt->execute([':section' => $sectionId]);

        foreach ($classes as $class) {
            $classType = ucfirst(strtolower(trim((string) ($class['classType'] ?? ''))));
            $days = is_array($class['days'] ?? null) ? array_values(array_filter($class['days'])) : [];
            $start = trim((string) ($class['start'] ?? ''));
            $end = trim((string) ($class['end'] ?? ''));
            $instructor = trim((string) ($class['instructor'] ?? ''));
            if (!in_array($classType, ['Lecture', 'Lab'], true) || !$days || $start === '' || $end === '' || $start >= $end || $instructor === '') {
                $conn->rollBack();
                respond(['success' => false, 'message' => 'Each class slot needs a valid type, day, time, and instructor'], 400);
            }
            $instructorStmt = $conn->prepare('INSERT INTO instructor (instructor_name) VALUES (:name) RETURNING instructor_id');
            $instructorStmt->execute([':name' => $instructor]);
            $instructorId = (int) $instructorStmt->fetchColumn();
            $roomId = null;
            if (!empty($class['room'])) {
                $parts = array_map('trim', explode(',', $class['room'], 2));
                $roomStmt = $conn->prepare('INSERT INTO room (building, room_num) VALUES (:building, :room) RETURNING room_id');
                $roomStmt->execute([':building' => $parts[0], ':room' => $parts[1] ?? '']);
                $roomId = (int) $roomStmt->fetchColumn();
            }
            foreach ($days as $day) {
                $scheduleStmt = $conn->prepare('INSERT INTO schedule (day_of_the_week, start_time, end_time, section_id) VALUES (:day, :start, :end, :section) RETURNING schedule_id');
                $scheduleStmt->execute([':day' => $day, ':start' => $start, ':end' => $end, ':section' => $sectionId]);
                $offeringStmt = $conn->prepare('INSERT INTO offering (subject_id, room_id, instructor_id, max_capacity, schedule_id, class_type) VALUES (:subject, :room, :instructor, :capacity, :schedule, :class_type)');
                $offeringStmt->execute([':subject' => $subjectId, ':room' => $roomId, ':instructor' => $instructorId, ':capacity' => $capacity, ':schedule' => $scheduleStmt->fetchColumn(), ':class_type' => $classType]);
            }
        }
        $conn->commit();
        respond(['success' => true, 'id' => $sectionId]);
    }

    if ($resource === 'sections') {
        if (($data['action'] ?? '') === 'toggle') {
            $sectionId = $data['id'] ?? null;
            $isOpen = filter_var($data['isOpen'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_numeric($sectionId) || $isOpen === null) {
                respond(['success' => false, 'message' => 'A valid section ID and open state are required'], 400);
            }

            $stmt = $conn->prepare('UPDATE section SET is_open = :is_open WHERE section_id = :id');
            $stmt->bindValue(':id', (int)$sectionId, PDO::PARAM_INT);
            $stmt->bindValue(':is_open', $isOpen, PDO::PARAM_BOOL);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $exists = $conn->prepare('SELECT 1 FROM section WHERE section_id = :id');
                $exists->execute([':id' => (int)$sectionId]);
                if (!$exists->fetchColumn()) {
                    respond(['success' => false, 'message' => 'Section not found'], 404);
                }
            }
            respond(['success' => true, 'id' => (int)$sectionId, 'isOpen' => $isOpen]);
        }

        if (empty($data['subjectId']) && !empty($data['subject_id'])) {
            $data['subjectId'] = $data['subject_id'];
        }
        foreach (['type', 'subjectId', 'instructor', 'start', 'end'] as $required) {
            if (empty($data[$required])) {
                respond(['success' => false, 'message' => "Missing section field: $required"], 400);
            }
        }
        $classType = ucfirst(strtolower(trim((string) ($data['classType'] ?? 'Lecture'))));
        if (!in_array($classType, ['Lecture', 'Lab'], true)) {
            respond(['success' => false, 'message' => 'Class type must be Lecture or Lab'], 400);
        }
        $conn->beginTransaction();

        if (!empty($data['id']) && is_numeric($data['id'])) {
            $sectionId = (int) $data['id'];
            $stmt = $conn->prepare('UPDATE section SET section_name = :name, subject_id = :subject WHERE section_id = :id');
            $stmt->execute([':id' => $sectionId, ':name' => $data['type'], ':subject' => $data['subjectId']]);

            // Rebuild the section's schedules so edited days and times replace the old values.
            $stmt = $conn->prepare('DELETE FROM offering WHERE offering_id IN (SELECT o.offering_id FROM offering o JOIN schedule sc ON sc.schedule_id = o.schedule_id WHERE sc.section_id = :section AND o.class_type = :class_type)');
            $stmt->execute([':section' => $sectionId, ':class_type' => $classType]);
            $stmt = $conn->prepare('DELETE FROM schedule sc WHERE sc.section_id = :section AND NOT EXISTS (SELECT 1 FROM offering o WHERE o.schedule_id = sc.schedule_id)');
            $stmt->execute([':section' => $sectionId]);
        } else {
            $stmt = $conn->prepare('SELECT section_id FROM section WHERE subject_id = :subject AND LOWER(TRIM(section_name)) = LOWER(TRIM(:name)) ORDER BY section_id LIMIT 1');
            $stmt->execute([':subject' => $data['subjectId'], ':name' => $data['type']]);
            $sectionId = (int) $stmt->fetchColumn();
            if (!$sectionId) {
                $stmt = $conn->prepare('INSERT INTO section (section_name, subject_id) VALUES (:name, :subject) RETURNING section_id');
                $stmt->execute([':name' => $data['type'], ':subject' => $data['subjectId']]);
                $sectionId = (int) $stmt->fetchColumn();
            }
        }

        $instructorStmt = $conn->prepare('INSERT INTO instructor (instructor_name) VALUES (:name) RETURNING instructor_id');
        $instructorStmt->execute([':name' => $data['instructor']]);
        $instructorId = (int) $instructorStmt->fetchColumn();

        $roomId = null;
        if (!empty($data['room'])) {
            $parts = array_map('trim', explode(',', $data['room'], 2));
            $roomStmt = $conn->prepare('INSERT INTO room (building, room_num) VALUES (:building, :room) RETURNING room_id');
            $roomStmt->execute([':building' => $parts[0], ':room' => $parts[1] ?? '']);
            $roomId = (int) $roomStmt->fetchColumn();
        }

        $days = is_array($data['days'] ?? null) ? $data['days'] : [$data['days'] ?? ''];
        $scheduleStmt = $conn->prepare('SELECT schedule_id FROM schedule WHERE section_id = :section AND day_of_the_week = :day AND start_time = :start AND end_time = :end LIMIT 1');
        $insertScheduleStmt = $conn->prepare('INSERT INTO schedule (day_of_the_week, start_time, end_time, section_id) VALUES (:day, :start, :end, :section) RETURNING schedule_id');
        foreach (array_filter($days) as $day) {
            $scheduleStmt->execute([':day' => $day, ':start' => $data['start'], ':end' => $data['end'], ':section' => $sectionId]);
            $scheduleId = (int) $scheduleStmt->fetchColumn();
            if (!$scheduleId) {
                $insertScheduleStmt->execute([':day' => $day, ':start' => $data['start'], ':end' => $data['end'], ':section' => $sectionId]);
                $scheduleId = (int) $insertScheduleStmt->fetchColumn();
            }

            $offeringStmt = $conn->prepare('SELECT o.offering_id FROM offering o WHERE o.schedule_id = :schedule AND o.class_type = :class_type LIMIT 1');
            $offeringStmt->execute([':schedule' => $scheduleId, ':class_type' => $classType]);
            $offeringId = $offeringStmt->fetchColumn();
        if ($offeringId) {
            $stmt = $conn->prepare('UPDATE offering SET subject_id = :subject, room_id = :room, instructor_id = :instructor, max_capacity = :capacity, schedule_id = :schedule, class_type = :class_type WHERE offering_id = :id');
            $stmt->execute([':id' => $offeringId, ':subject' => $data['subjectId'], ':room' => $roomId, ':instructor' => $instructorId, ':capacity' => $data['maxCapacity'] ?? 0, ':schedule' => $scheduleId, ':class_type' => $classType]);
        } else {
            $stmt = $conn->prepare('INSERT INTO offering (subject_id, room_id, instructor_id, max_capacity, schedule_id, class_type) VALUES (:subject, :room, :instructor, :capacity, :schedule, :class_type)');
            $stmt->execute([':subject' => $data['subjectId'], ':room' => $roomId, ':instructor' => $instructorId, ':capacity' => $data['maxCapacity'] ?? 0, ':schedule' => $scheduleId, ':class_type' => $classType]);
        }
        }
        $conn->commit();
        respond(['success' => true, 'id' => $sectionId]);
    }

    if ($resource === 'offerings') {
        $subjectId = $data['subjectId'] ?? null;
        $sectionId = $data['sectionId'] ?? null;
        $capacity = (int) ($data['maxCapacity'] ?? 0);
        if (!is_numeric($subjectId) || !is_numeric($sectionId)) {
            respond(['success' => false, 'message' => 'Subject and section IDs are required'], 400);
        }

        $conn->beginTransaction();
        $scheduleStmt = $conn->prepare('SELECT schedule_id FROM schedule WHERE section_id = :section ORDER BY schedule_id LIMIT 1');
        $scheduleStmt->execute([':section' => $sectionId]);
        $scheduleId = $scheduleStmt->fetchColumn();
        if (!$scheduleId) {
            $conn->rollBack();
            respond(['success' => false, 'message' => 'The section has no schedule yet'], 400);
        }

        $publishStmt = $conn->prepare('UPDATE admin_section_draft SET published = TRUE WHERE section_id = :section AND subject_id = :subject');
        $publishStmt->execute([':section' => $sectionId, ':subject' => $subjectId]);

        $offeringStmt = $conn->prepare('SELECT offering_id FROM offering WHERE subject_id = :subject AND schedule_id = :schedule LIMIT 1');
        $offeringStmt->execute([':subject' => $subjectId, ':schedule' => $scheduleId]);
        $offeringId = $offeringStmt->fetchColumn();
        if ($offeringId) {
            $updateStmt = $conn->prepare('UPDATE offering SET max_capacity = :capacity WHERE offering_id = :id');
            $updateStmt->execute([':capacity' => $capacity, ':id' => $offeringId]);
        } else {
            $insertStmt = $conn->prepare('INSERT INTO offering (subject_id, max_capacity, schedule_id) VALUES (:subject, :capacity, :schedule) RETURNING offering_id');
            $insertStmt->execute([':subject' => $subjectId, ':capacity' => $capacity, ':schedule' => $scheduleId]);
            $offeringId = $insertStmt->fetchColumn();
        }
        $conn->commit();
        respond(['success' => true, 'id' => (int) $offeringId]);
    }

    if ($resource === 'students') {
        if (empty($data['studentId']) || !is_numeric($data['studentId']) || empty($data['name'])) {
            respond(['success' => false, 'message' => 'Student ID and name are required'], 400);
        }
        $nameParts = preg_split('/\s+/', trim($data['name']), 2);
        $email = strtolower($data['studentId']) . '@vetsched.local';
        $params = [
            ':id' => $data['studentId'],
            ':email' => $email,
            ':first' => $nameParts[0],
            ':last' => $nameParts[1] ?? ''
        ];
        if (!empty($data['id']) && is_numeric($data['id'])) {
            $stmt = $conn->prepare('UPDATE account SET first_name = :first, last_name = :last WHERE student_id = :existing_id');
            $params[':existing_id'] = $data['id'];
        } else {
            $stmt = $conn->prepare('INSERT INTO account (student_id, email, first_name, last_name) VALUES (:id, :email, :first, :last)');
        }
        $stmt->execute($params);
        respond(['success' => true, 'id' => (int) ($data['id'] ?? $data['studentId'])]);
    }

    respond(['success' => false, 'message' => 'Unknown resource'], 404);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    respond(['success' => false, 'message' => $e->getMessage()], 500);
}
?>