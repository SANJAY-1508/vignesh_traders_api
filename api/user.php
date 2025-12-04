<?php

include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000"); // Allow only your React app
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE"); // Allow HTTP methods
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Allow headers
header("Access-Control-Allow-Credentials: true"); // If needed for cookies/auth

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// if ($loginpass == 1) {

// <<<<<<<<<<===================== This is to list users =====================>>>>>>>>>>
if (isset($obj->search_text)) {
    $search_text = $obj->search_text;
    $sql = "SELECT * FROM `users` WHERE `delete_at` = 0 AND `name` LIKE '%$search_text%'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["user"][$count] = $row;
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "User Details Not Found";
        $output["body"]["user"] = [];
    }
}

// <<<<<<<<<<===================== This is to Create and Edit users =====================>>>>>>>>>>
else if (isset($obj->name) && isset($obj->phone_number) && isset($obj->password) && isset($obj->user_type) && isset($obj->staff_permission)) {


    $name = $obj->name;
    $phone_number = $obj->phone_number;
    $password = $obj->password;
    $user_type = $obj->user_type;
    $staff_permission = $obj->staff_permission;


    if (!empty($name) && !empty($phone_number) && !empty($password)) {

        if (!preg_match('/[^a-zA-Z0-9., ]+/', $name)) {

            if (numericCheck($phone_number) && strlen($phone_number) == 10) {


                if (isset($obj->user_id)) {
                    $edit_id = $obj->user_id;



                    $updateUser = "UPDATE `users` SET `name`='$name', `phone_number`='$phone_number', `password`='$password', `user_type`='$user_type', `staff_permission`='$staff_permission' WHERE `user_id`='$edit_id'";


                    if ($conn->query($updateUser)) {
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully User Details Updated";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Failed to connect. Please try again." . $conn->error;
                    }
                } else {

                    $mobileCheck = $conn->query("SELECT * FROM `users` WHERE `phone_number`='$phone_number'");
                    if ($mobileCheck->num_rows == 0) {


                        $createUser = "INSERT INTO `users` (`name`, `phone_number`,`password` ,`user_type` ,`staff_permission`, `delete_at`) VALUES ('$name', '$phone_number',  '$password',  '$user_type',  '$staff_permission', '0') ";

                        if ($conn->query($createUser)) {
                            $id = $conn->insert_id;
                            $enId = uniqueID('users', $id);

                            $updateUserId = "update users set user_id ='$enId' where `id`='$id'";
                            $conn->query($updateUserId);
                            $output["head"]["code"] = 200;
                            $output["head"]["msg"] = "Successfully User Created";
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to connect. Please try again.";
                        }
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Mobile Number Already Exist.";
                    }
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Invalid Phone Number.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Username Should be Alphanumeric.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
}


// <<<<<<<<<<===================== This is to Delete the users =====================>>>>>>>>>>
else if (isset($obj->delete_user_id)) {

    $delete_user_id = $obj->delete_user_id;



    if (!empty($delete_user_id)) {


        $deleteuser = "UPDATE `users` SET `delete_at`=1 where `user_id`='$delete_user_id'";
        if ($conn->query($deleteuser) === true) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "successfully user deleted !.";
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "faild to deleted.please try againg.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
    $output["head"]["inputs"] = $obj;
}




echo json_encode($output, JSON_NUMERIC_CHECK);
