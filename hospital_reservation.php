#!/usr/bin/php -q
<?php
/**
 * 병원 예약 ARS 시스템 (재시도 버전)
 * 
 * - 각 단계(월/일/오전오후/시간/분/확인)별로 최대 3번까지 재시도.
 * - 아무 입력이 없거나 범위 밖 값이면 "잘못된 입력" 안내 후 재시도.
 * - 3회 실패 시 통화 종료.
 */
require_once('phpagi.php');
$agi = new AGI();
$agi->verbose("병원 예약 ARS 시스템 시작", 1);

// 최대 재시도 횟수
define('MAX_RETRY', 3);

// 입력 타임아웃 (ms 단위) - 10초
define('TIMEOUT_MS', 10000);

// CallerID
$callerId = $agi->request['agi_callerid'] ?? 'Unknown';
$agi->verbose("전화한 사람의 전화번호: {$callerId}", 1);

// 예약 정보
$reservation = array(
    'month'  => 0,
    'day'    => 0,
    'ampm'   => '', // "오전" 또는 "오후"
    'hour'   => 0,
    'minute' => 0,
);

// Discord 웹훅 (내용 변경 X)
$webhook_url = "https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
// (여기서 webhook_url은 실제 URL로 변경해야 함)
// (예시 URL로는 작동하지 않음)
// (실제 사용 시에는 Discord 웹훅 URL을 입력해야 함)
// (웹훅 URL은 Discord 서버에서 생성 가능)
// (Discord 서버에서 웹훅을 생성하고, URL을 복사하여 여기에 붙여넣기)
// (웹훅 URL은 비공식 API로 사용되므로, Discord의 정책에 따라 사용해야 함)
// (Discord의 정책에 따라 사용해야 하며, 비공식 API로 사용될 수 있음)

// ----------------------------------------
// 전화 응답
// ----------------------------------------
$agi->answer();
$agi->verbose("전화 응답 완료", 1);

// 환영 메시지
$agi->stream_file("hospital_reservation/welcome", "");
$agi->verbose("환영 메시지 재생 완료", 1);
$agi->exec("Wait", "1"); // 1초 대기

/**
 * 함수: getDataWithRetry
 *  - 안내멘트 재생 후, get_data()로 입력을 받고, 유효성 검사를 통과하면 반환
 *  - 통과 못 하면 최대 3회 재시도. 그래도 실패하면 종료
 *
 * @param AGI     $agi
 * @param string  $promptFile     : 안내멘트 사운드(메인)
 * @param string  $invalidFile    : 잘못된 입력일 때 재생할 사운드
 * @param int     $maxDigits      : 입력 자릿수
 * @param callable $validateFn    : 입력값 검증 함수( string -> bool )
 * @return string                 : 최종 유효 입력(문자열)
 */
function getDataWithRetry($agi, $promptFile, $invalidFile, $maxDigits, $validateFn) {
    for ($attempt = 1; $attempt <= MAX_RETRY; $attempt++) {
        // 첫 시도엔 $promptFile 재생, 그 후에는 필요하면 반복 안내 재생 가능
        $agi->stream_file($promptFile, "");
        // get_data(사운드, 타임아웃, 자릿수)
        // 여기서 'beep'는 Asterisk 기본 사운드 (hospital_reservation/beep 아님)
        $result = $agi->get_data("beep", TIMEOUT_MS, $maxDigits);
        $input = trim($result['result'] ?? ''); // 사용자가 입력한 값(문자열)

        // 유효성 검사
        if (call_user_func($validateFn, $input)) {
            return $input; // 유효 → 즉시 반환
        } else {
            // 잘못된 입력 → 안내 후 재시도
            $agi->stream_file($invalidFile, "");
            $agi->verbose("잘못된 입력: '{$input}' (시도: {$attempt}/".MAX_RETRY.")", 1);

            if ($attempt >= MAX_RETRY) {
                $agi->verbose("재시도 초과. 통화 종료", 1);
                $agi->hangup();
                exit;
            }
        }
    }
    // 여기 도달할 일은 거의 없음
    $agi->hangup();
    exit;
}

// -------------------------------------------------------
// 1. 월 선택 (1~12)
// -------------------------------------------------------
$monthStr = getDataWithRetry(
    $agi,
    "hospital_reservation/select_month",   // 안내멘트
    "hospital_reservation/invalid_month",  // 잘못된 입력 안내
    2, // 최대 자릿수
    function($val) {
        // 무응답 -> $val="" or -1
        if (!ctype_digit($val)) return false;
        $m = (int)$val;
        return ($m >= 1 && $m <= 12);
    }
);
$month = (int)$monthStr;
$reservation['month'] = $month;

// 안내
$agi->stream_file("hospital_reservation/you_selected", "");
$agi->say_number($month);
$agi->stream_file("hospital_reservation/month", "");
$agi->verbose("월 선택 완료: {$month}", 1);

// -------------------------------------------------------
// 2. 일 선택 (1~31)
// -------------------------------------------------------
$dayStr = getDataWithRetry(
    $agi,
    "hospital_reservation/select_day",
    "hospital_reservation/invalid_day",
    2,
    function($val) {
        if (!ctype_digit($val)) return false;
        $d = (int)$val;
        return ($d >= 1 && $d <= 31);
    }
);
$day = (int)$dayStr;
$reservation['day'] = $day;

$agi->stream_file("hospital_reservation/you_selected", "");
$agi->say_number($day);
$agi->stream_file("hospital_reservation/day", "");
$agi->verbose("일 선택 완료: {$day}", 1);

// -------------------------------------------------------
// 3. 오전/오후 선택 (1=오전, 2=오후)
// -------------------------------------------------------
$ampmStr = getDataWithRetry(
    $agi,
    "hospital_reservation/select_ampm",
    "hospital_reservation/invalid_ampm",
    1,
    function($val) {
        return ($val === '1' || $val === '2');
    }
);
$ampm = ($ampmStr === '1') ? "오전" : "오후";
$reservation['ampm'] = $ampm;

$agi->stream_file("hospital_reservation/you_selected", "");
$agi->stream_file("hospital_reservation/" . (($ampmStr === '1') ? "am" : "pm"), "");
$agi->verbose("오전/오후 선택 완료: {$ampm}", 1);

// -------------------------------------------------------
// 4. 시간 선택
//    오전(1) → 9~12
//    오후(2) → 1~6
// -------------------------------------------------------
$hourStr = getDataWithRetry(
    $agi,
    "hospital_reservation/select_hour",
    "hospital_reservation/invalid_hour",
    2,
    function($val) use ($ampmStr) {
        if (!ctype_digit($val)) return false;
        $h = (int)$val;
        if ($ampmStr === '1') {
            // 오전
            return ($h >= 9 && $h <= 12);
        } else {
            // 오후
            return ($h >= 1 && $h <= 6);
        }
    }
);
$hour = (int)$hourStr;
$reservation['hour'] = $hour;

$agi->stream_file("hospital_reservation/you_selected", "");
$agi->say_number($hour);
$agi->stream_file("hospital_reservation/hour", "");
$agi->verbose("시간 선택 완료: {$hour}", 1);

// -------------------------------------------------------
// 5. 분 선택 (0,10,20,30,40,50)
// -------------------------------------------------------
$minuteStr = getDataWithRetry(
    $agi,
    "hospital_reservation/select_minute",
    "hospital_reservation/invalid_minute",
    2,
    function($val) {
        if (!ctype_digit($val)) return false;
        $allowed = [0,10,20,30,40,50];
        $num = (int)$val;
        return in_array($num, $allowed);
    }
);
$minute = (int)$minuteStr;
$reservation['minute'] = $minute;

$agi->stream_file("hospital_reservation/you_selected", "");
$agi->say_number($minute);
$agi->stream_file("hospital_reservation/minute", "");
$agi->verbose("분 선택 완료: {$minute}", 1);

// -------------------------------------------------------
// 6. 예약 확인
// -------------------------------------------------------
$agi->stream_file("hospital_reservation/confirm_reservation", "");
$agi->verbose("예약 확인 메시지 재생 시작", 1);
$agi->stream_file("hospital_reservation/your_reservation", "");

// "XX월 XX일 오전/오후 XX시 XX분" 안내
$agi->say_number($reservation['month']);
$agi->stream_file("hospital_reservation/month", "");
$agi->say_number($reservation['day']);
$agi->stream_file("hospital_reservation/day", "");
$agi->stream_file("hospital_reservation/" . (($ampmStr === '1') ? "am" : "pm"), "");
$agi->say_number($reservation['hour']);
$agi->stream_file("hospital_reservation/hour", "");
$agi->say_number($reservation['minute']);
$agi->stream_file("hospital_reservation/minute", "");
$agi->verbose("예약 확인 메시지 재생 완료", 1);

// -------------------------------------------------------
// 최종 확인 (1=확정, 2=취소)
// -------------------------------------------------------
$confirmStr = getDataWithRetry(
    $agi,
    "hospital_reservation/press_confirm",
    "hospital_reservation/invalid_ampm", // 재활용 or 별도 invalid_confirm 사용
    1,
    function($val) {
        return ($val === '1' || $val === '2');
    }
);

if ($confirmStr === '1') {
    // 예약 확정
    $agi->stream_file("hospital_reservation/reservation_confirmed", "");
    $agi->verbose("예약 확정 선택됨", 1);

    // 여기서 DB 저장 등 처리 가능

    // Discord 웹훅 전송 (내용 변경 X)
    $message = "새로운 예약이 등록되었습니다.\n";
    $message .= "전화번호: {$callerId}\n";
    $message .= "예약 일시: {$reservation['month']}월 {$reservation['day']}일 {$reservation['ampm']} {$reservation['hour']}시 {$reservation['minute']}분";
    $agi->verbose("Discord 웹훅 전송 메시지: {$message}", 1);

    $payload = json_encode(["content" => $message]);
    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $curl_result = curl_exec($ch);

    if (curl_errno($ch)) {
        $agi->verbose("Discord 웹훅 전송 오류: " . curl_error($ch), 1);
    } else {
        $agi->verbose("Discord 웹훅 전송 성공", 1);
    }
    curl_close($ch);

} else {
    // 예약 취소
    $agi->stream_file("hospital_reservation/reservation_canceled", "");
    $agi->verbose("예약 취소 선택됨", 1);
}

// -------------------------------------------------------
// 추가: 예약 결과 콜백 웹훅 전송
// URL: https://mhdw95wb-5000.asse.devtunnels.ms/api/webhook/ars-callback
// Method: POST
// Content-Type: application/json
// Request Body:
// {
//     "reservation_id": 전화번호,
//     "status": "confirmed" 또는 "rejected",
//     "callback_time": "YYYY-MM-DD HH:mm:ss"
// }
$callbackPayload = json_encode([
    "reservation_id" => $callerId,  // 예약ID 대신 전화번호 사용
    "status"       => ($confirmStr === '1') ? "confirmed" : "rejected",
    "callback_time"=> date("Y-m-d H:i:s")
]);
$callback_url = "https://6a34-61-254-29-178.ngrok-free.app/api/webhook/ars-callback";
$ch = curl_init($callback_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $callbackPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$callback_result = curl_exec($ch);
if (curl_errno($ch)) {
    $agi->verbose("Webhook callback error: " . curl_error($ch), 1);
} else {
    $agi->verbose("Webhook callback success", 1);
}
curl_close($ch);

// -------------------------------------------------------
// 감사 메시지
// -------------------------------------------------------
$agi->stream_file("hospital_reservation/thank_you", "");
$agi->verbose("감사 메시지 재생 완료", 1);

// 종료
$agi->hangup();
$agi->verbose("통화 종료", 1);
?>
