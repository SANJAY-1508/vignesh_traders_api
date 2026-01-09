<?php
include 'config/dbconfig.php';

$allowed_origins = [
    "http://localhost:3000",
<<<<<<< HEAD
    "http://192.168.1.6:3000"
=======
    "http://192.168.1.71:3000"
>>>>>>> 3934453c992752b5d20e5feb120645f62a832c39
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Optional: Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');


if (isset($obj->phone_number) && isset($obj->password) && isset($obj->company_id)) {

    $phone_number = $obj->phone_number;
    $password = $obj->password;
    $company_id = $obj->company_id;

    if (!empty($phone_number) && !empty($password) && !empty($company_id)) {

        if (numericCheck($phone_number) && strlen($phone_number) == 10) {

            // <<<<<<<<<<===================== Checking the user table =====================>>>>>>>>>>
            $result = $conn->query("SELECT * FROM `users` WHERE `phone_number`='$phone_number' AND `delete_at` = 0");
            if ($result->num_rows > 0) {
                if ($row = $result->fetch_assoc()) {

                    if ($row['password'] == $password) {
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Success";
                        $output["body"]["data"] = $row;
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Invalid Credentials";
                    }
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "User not found";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid Mobile Number.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
}


echo json_encode($output, JSON_NUMERIC_CHECK);
