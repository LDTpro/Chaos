<?php
require_once 'config.php';

$conn = mysqli_connect($DBHOST, $DBUSER, $DBPW, $DBNAME);

if ( isset($_GET['hub_verify_token']) ) {
  if ( $_GET['hub_verify_token'] === $VERIFY_TOKEN ) {
    die($_GET['hub_challenge']);
  }

  die('Verification token mismatch');
}

$data = json_decode(file_get_contents('php://input'), true);

if ( isset($data['entry'][0]['messaging'][0]['sender']['id']) ) {
	$sender = $data['entry'][0]['messaging'][0]['sender']['id'];
}

if ( isset($data['entry'][0]['messaging'][0]['message']['text']) ) {
	$message = $data['entry'][0]['messaging'][0]['message']['text'];

  if ( strtolower($message) === "bot" ) {
    $type = hasSession($sender) ? 'stop' : 'start';

    sendButton($sender, 'Bạn muốn làm gì tiếp theo?', $type);
  } else {
    forwardMessage($sender, $message);
  }
}

if ( isset($data['entry'][0]['messaging'][0]['postback']['payload']) ) {
	$postback = $data['entry'][0]['messaging'][0]['postback']['payload'];

  if ( $postback === "start" ) {
    if ( ! hasSession($sender) ) {
      findRelationship($sender);
    } else {
      sendMessage($sender, "Bạn không thể tạo thêm cuộc đối thoại mới, hãy hủy cuộc đối thoại hiện có rồi thử lại.");
    }
  }

  if ( $postback === "stop" ) {
    if ( ! hasSession($sender) ) {
      sendMessage($sender, "Bạn không có cuộc đối thoại nào cần hủy.");
    } else {
      deleteRelationship($sender);
    }
  }

  if ( $postback === "queue_stop" ) {
    if ( ! hasSession($sender) ) {
      if ( isUserQueue($sender) ) {
        deleteUserQueue($sender);
        sendMessage($sender, "Bạn đã ra khỏi hàng đợi.");
      } else {
        sendMessage($sender, "Bạn đang không có ở trong hàng đợi, hãy thử lại.");
      }
    } else {
      sendMessage($sender, "Bạn đã ra khỏi hàng đợi trước đó.");
    }
  }
}

function request($jsondata) {
  global $ACCESS_TOKEN;

  $url = "https://graph.facebook.com/v2.9/me/messages?access_token=$ACCESS_TOKEN";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $jsondata);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_exec($ch);
}

function sendMessage($receiver, $content) {
  $payload = '{"recipient":{"id":"' . $receiver . '"},"message":{"text":"' . $content . '"}}';
  request($payload);
}

function sendButton($receiver, $message, $type) {
  switch ($type) {
    case 'start':
      $typeButton = '{"type":"postback","title":"Bắt đầu","payload":"start"}';
      break;

    case 'stop':
      $typeButton = '{"type":"postback","title":"Kết thúc","payload":"stop"}';
      break;

    case 'queue_stop':
      $typeButton = '{"type":"postback","title":"Hủy đợi","payload":"queue_stop"}';
      break;
  }

  $payload = '{"recipient": {"id": ' . $receiver . '}, "message":{"attachment":{"type":"template","payload":{"template_type":"button","text":"'. $message . '","buttons":[' . $typeButton . ']}}}}';
  request($payload);
}

function isUserExist($userid) {
  global $conn;

  $result = mysqli_query($conn, "SELECT * from users WHERE id = $userid LIMIT 1");
  $row = mysqli_num_rows($result);
  return $row;
}

function isUserQueue($userid) {
  global $conn;

  $result = mysqli_query($conn, "SELECT queue from users WHERE id = $userid");
  $row = mysqli_fetch_assoc($result);

  return intval($row['queue']) !== 0;
}

function hasSession($userid) {
  global $conn;

  $result = mysqli_query($conn, "SELECT status from users WHERE id = $userid");
  $row = mysqli_fetch_assoc($result);

  return intval($row['status']) !== 0;
}

function getRelationship($userid) {
  global $conn;

  $result = mysqli_query($conn, "SELECT relationship from users WHERE id = $userid");
  $row = mysqli_fetch_assoc($result);
  $relationship = $row['relationship'];
  return $relationship;
}

function addRelationship($user1, $user2) {
  global $conn;

  mysqli_query($conn, "UPDATE users SET status = 1, relationship = $user2, queue = 0 WHERE id = $user1");
  mysqli_query($conn, "UPDATE users SET status = 1, relationship = $user1, queue = 0 WHERE id = $user2");
}

function deleteRelationship($userid) {
  global $conn;

  $partner = getRelationship($userid);
  mysqli_query($conn, "UPDATE users SET status = 0, relationship = NULL WHERE id = $userid");
  mysqli_query($conn, "UPDATE users SET status = 0, relationship = NULL WHERE id = $partner");
  sendMessage($userid, "Bạn đã rời khỏi cuộc đối thoại.");
  sendMessage($partner, "Người kia đã rời khỏi cuộc đối thoại.");
}

function deleteUserQueue($userid) {
  global $conn;

  mysqli_query($conn, "UPDATE users SET queue = 0 WHERE id = $userid");
}

function forwardMessage($userid, $msg) {
  $partner = getRelationship($userid);
  if ($partner != NULL)
    sendMessage($partner, $msg);
}

function addUserQueue($userid) {
  global $conn;

  mysqli_query($conn, "UPDATE users SET queue = 1 WHERE id = $userid");
}

function findRelationship($userid) {
  global $conn;

  if ( ! isUserExist($userid) ) {
    mysqli_query($conn, "INSERT INTO users (id, status, queue) VALUES ($userid, 0, 0)");
  }

  if ( isUserQueue($userid) ) {
    return sendButton($userid, 'Bạn đang ở trong hàng đợi, xin hãy chờ trong chốc lát...', 'queue_stop');
  }

  $result = mysqli_query($conn, "SELECT id FROM users WHERE queue = 1 AND id != $userid ORDER BY RAND() LIMIT 1");
  $row = mysqli_fetch_assoc($result);
  $partner = $row['id'];
  if ( ! $partner ) {
    addUserQueue($userid);
    sendButton($userid, 'Bạn đã được thêm vào hàng đợi, vui lòng chờ trong chốc lát...', 'queue_stop');
  } else {
    addRelationship($userid, $partner);
    sendMessage($userid, "You have been connected with the stranger");
    sendMessage($partner, "You have been connected with the stranger");
  }
}

mysqli_close($conn);
?>