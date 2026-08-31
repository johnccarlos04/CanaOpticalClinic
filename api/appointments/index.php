<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/index.php
//  GET → returns appointments filtered by caller's role:
//    admin/staff  → all appointments
//    doctor       → only their appointments
//    patient      → only their appointments
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role      = $_SESSION['role']       ?? '';
$profileId = $_SESSION['profile_id'] ?? '';

function mapRow(array $r): array {
    $rr = json_decode($r['reschedule_request'] ?? '', true);
    return [
        'id'                  => $r['id'],
        'patientId'           => $r['patient_id'],
        'patientName'         => $r['patient_name'] ?? '',
        'doctorId'            => $r['doctor_id'],
        'doctorName'          => $r['doctor_name']  ?? '',
        'date'                => $r['date'],
        'time'                => $r['time'],
        'type'                => $r['type']  ?? '',
        'status'              => $r['status'],
        'source'              => $r['source'] ?? 'online',
        'notes'               => $r['notes'] ?? '',
        'cancellationReason'  => $r['cancellation_reason']  ?? null,
        'disapprovalReason'   => $r['disapproval_reason']   ?? null,
        'rescheduleNote'      => $r['reschedule_note']      ?? null,
        'rescheduleRequest'   => is_array($rr) ? $rr : null,
        'reminderSentAt'      => $r['reminder_sent_at'] ?? null,
        'confirmedAt'         => $r['confirmed_at']     ?? null,
        'rescheduledAt'       => $r['rescheduled_at']   ?? null,
    ];
}

try {
    $pdo = getDB();

    // Auto-transition approved appointments whose date has passed to 'no-show'.
    // Read the affected rows first (not just the count) so each one can be
    // charged against its patient's no-show tally — the WHERE clause only
    // ever matches a row once (it flips out of 'approved' immediately after),
    // so this can't double-count a patient across repeated poll requests.
    $dueNoShow = $pdo->query(
        "SELECT id, patient_id, doctor_id, doctor_name, date, time FROM appointments
         WHERE status = 'approved' AND date < CURDATE()"
    )->fetchAll();
    if ($dueNoShow) {
        $pdo->exec(
            "UPDATE appointments SET status = 'no-show'
             WHERE status = 'approved' AND date < CURDATE()"
        );
        foreach ($dueNoShow as $missed) {
            if ($missed['doctor_id']) {
                offerNextWaitlistSlot($pdo, $missed['doctor_id'], $missed['date'], $missed['time']);
            }
            if (!$missed['patient_id']) continue;
            $justRestricted = recordNoShow($pdo, $missed['patient_id']);
            $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
            $ps->execute([$missed['patient_id']]);
            $puid = $ps->fetchColumn();
            if ($puid) {
                $fmtDate = date('M j, Y', strtotime($missed['date']));
                $msg = "You were marked as a no-show for your appointment with {$missed['doctor_name']} on {$fmtDate} at {$missed['time']}.";
                if ($justRestricted) {
                    $msg .= ' Due to repeated missed appointments, online booking has been temporarily restricted for your account. Please contact the clinic directly to schedule.';
                }
                createNotification($pdo, (int)$puid, 'no_show', 'Missed Appointment', $msg);
            }
        }
    }

    if (in_array($role, ['admin', 'staff'], true)) {
        $stmt = $pdo->query('SELECT * FROM appointments ORDER BY date DESC, time ASC');
    } elseif ($role === 'doctor') {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE doctor_id = ? ORDER BY date DESC, time ASC');
        $stmt->execute([$profileId]);
    } elseif ($role === 'patient') {
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE patient_id = ? ORDER BY date DESC, time ASC');
        $stmt->execute([$profileId]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        return; // unreachable but satisfies static analysis
    }

    $rows = $stmt->fetchAll();
    jsonResponse(['success' => true, 'appointments' => array_map('mapRow', $rows)]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
