<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/taken.php
//  GET ?doctorId=D001&date=2026-07-15
//  → { success:true, taken:[{time:"9:00 AM",duration:30},…], defaultDuration:30 }
//
//  GET ?doctorIds=D001,D002,D003&date=2026-07-15   (batch/"any doctor" mode)
//  → { success:true, byDoctor:{ D001:[{time,duration},…], D002:[…] }, defaultDuration:30 }
//
//  Returns the booked (non-cancelled/disapproved) appointment times
//  for a given doctor on a given date, with each appointment's own
//  service duration (from clinic_services) so the frontend can
//  compute per-slot gap zones correctly. The batch form powers the
//  "any available optometrist" booking path, which needs the same
//  data for several doctors at once to compute union availability.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('GET');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$doctorId  = trim($_GET['doctorId']  ?? '');
$doctorIds = trim($_GET['doctorIds'] ?? '');
$date      = trim($_GET['date']      ?? '');
$excludeId = trim($_GET['excludeId'] ?? '');

if ((!$doctorId && !$doctorIds) || !$date) {
    jsonResponse(['success' => false, 'message' => 'doctorId (or doctorIds) and date are required.']);
}

try {
    $pdo = getDB();

    $durStr = $pdo->query(
        'SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1'
    )->fetchColumn();
    preg_match('/(\d+)/', $durStr ?: '30', $dm);
    $defaultDuration = isset($dm[1]) ? (int)$dm[1] : 30;

    // ── Batch mode: several doctors at once, grouped per doctor ──
    if ($doctorIds) {
        $ids = array_values(array_filter(array_map('trim', explode(',', $doctorIds))));
        $byDoctor = [];
        foreach ($ids as $id) {
            $byDoctor[$id] = _takenTimesForDoctor($pdo, $id, $date, $defaultDuration, $excludeId);
        }
        jsonResponse(['success' => true, 'byDoctor' => $byDoctor, 'defaultDuration' => $defaultDuration]);
    }

    $taken = _takenTimesForDoctor($pdo, $doctorId, $date, $defaultDuration, $excludeId);
    jsonResponse(['success' => true, 'taken' => $taken, 'defaultDuration' => $defaultDuration]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}

// JOIN clinic_services so each booked slot carries its own duration.
// COALESCE falls back to the clinic-wide default if the service name
// doesn't match (renamed service, legacy data, etc.).
// excludeId lets the reschedule picker omit the appointment being moved
// so its own current slot does not show as taken.
function _takenTimesForDoctor(PDO $pdo, string $doctorId, string $date, int $defaultDuration, string $excludeId): array {
    // patient_id rides along so the picker can tell "taken by someone
    // else" (still waitlist-able) apart from "taken by this same patient's
    // own other appointment" (see wizBuildTimeSlots() in main.js) — offering
    // a waitlist join for a slot the patient already holds themselves
    // doesn't make sense.
    $sql = "SELECT a.time, a.patient_id, COALESCE(cs.duration, :def) AS duration
            FROM appointments a
            LEFT JOIN clinic_services cs ON cs.name = a.type
            WHERE a.doctor_id = :doc AND a.date = :date
              AND a.status NOT IN ('cancelled','disapproved')"
         . ($excludeId ? ' AND a.id != :excl' : '');
    $stmt = $pdo->prepare($sql);
    $params = [':def' => $defaultDuration, ':doc' => $doctorId, ':date' => $date];
    if ($excludeId) $params[':excl'] = $excludeId;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $taken = array_map(fn($r) => [
        'time'      => $r['time'],
        'duration'  => (int)$r['duration'],
        'patientId' => $r['patient_id'],
    ], $rows);

    // Slots currently held by an active (unexpired) waitlist offer to
    // someone else aren't in `appointments` yet — fold them in too so the
    // picker greys them out instead of only bouncing at submit time.
    $held = $pdo->prepare(
        "SELECT time FROM appointment_waitlist
         WHERE doctor_id = ? AND date = ? AND status = 'offered' AND offer_expires_at > NOW()"
    );
    $held->execute([$doctorId, $date]);
    foreach ($held->fetchAll(PDO::FETCH_COLUMN) as $heldTime) {
        $taken[] = ['time' => $heldTime, 'duration' => $defaultDuration];
    }

    return array_values($taken);
}
