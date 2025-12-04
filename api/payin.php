<?php
include 'config/dbconfig.php';
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Access-Control-Allow-Origin: http://localhost:3000' );
header( 'Access-Control-Allow-Methods: POST, PUT, DELETE' );
header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
header( 'Access-Control-Allow-Credentials: true' );

if ( $_SERVER[ 'REQUEST_METHOD' ] == 'OPTIONS' ) {
    http_response_code( 200 );
    exit();
}

$json = file_get_contents( 'php://input' );
$obj = json_decode( $json, true );
$output = array();
$compID = $_GET[ 'id' ];
date_default_timezone_set( 'Asia/Calcutta' );

class BillNoCreation
 {
    public static function create( $params )
 {
        $prefix_name = $params[ 'prefix_name' ];
        $crtFinancialYear = self::getFinancialYear();
        $oldBillNumber = $params[ 'billno' ];

        // If the old bill number is 0, you can uncomment the logic below
        // if ( $oldBillNumber == 0 ) {
        //     $oldBillNumber = "{$prefix_name}/{$currentBillNumber}/{$crtFinancialYear}";
        // }

        $lastBillNumber = explode( '/', $oldBillNumber )[ 1 ];
        $currentBillNumber = intval( $lastBillNumber ) + 1;

        $currentBillNumber = self::billNumberFormat( $currentBillNumber );

        $result = "{$prefix_name}/{$currentBillNumber}/{$crtFinancialYear}";
        return $result;
    }

    private static function getFinancialYear()
 {
        // Logic to determine the current financial year
        $currentYear = date( 'Y' );
        $currentMonth = date( 'm' );

        if ( $currentMonth >= 4 ) {
            // FY starts in April of the current year
            return substr( $currentYear, 2 ) . '-' . substr( $currentYear + 1, 2 );
        } else {
            // FY starts in April of the previous year
            return substr( $currentYear - 1, 2 ) . '-' . substr( $currentYear, 2 );
        }
    }

    private static function billNumberFormat( $number )
 {
        // Format the bill number as needed ( e.g., pad with zeros )
        return str_pad( $number, 3, '0', STR_PAD_LEFT );
        // Change 3 to the required number of digits
    }
}

function fetchQuery( $conn, $sql, $params )
 {
    $stmt = $conn->prepare( $sql );
    if ( !$stmt ) {
        return [ 'status' => 500, 'msg' => 'Prepare failed: (' . $conn->errno . ') ' . $conn->error, 'data' => null ];
    }

    if ( $params ) {
        $stmt->bind_param( str_repeat( 's', count( $params ) ), ...$params );
    }

    if ( !$stmt->execute() ) {
        return [ 'status' => 500, 'msg' => 'Execute failed: (' . $stmt->errno . ') ' . $stmt->error, 'data' => null ];
    }

    $result = $stmt->get_result();
    return [
        'status' => 200,
        'msg' => 'Query successful',
        'data' => $result ? $result->fetch_all( MYSQLI_ASSOC ) : [],
    ];
}

// List Payins
if ( $_SERVER[ 'REQUEST_METHOD' ] == 'POST' && isset( $obj[ 'search_text' ] ) ) {
    $search_text = $obj[ 'search_text' ];
    $party_id = $obj[ 'party_id' ] ?? null;
    $from_date = $obj[ 'from_Date' ] ?? null;
    $to_date = $obj[ 'to_date' ] ?? null;

    $sql = "SELECT payin_id, party_id, party_details, receipt_no, company_details, 
                   DATE_FORMAT(receipt_date, '%Y-%m-%d') AS receipt_date, paid 
            FROM payin 
            WHERE delete_at = '0' AND company_id = ? 
            AND (party_id = ? OR JSON_EXTRACT(party_details, '$.party_name') = ? 
            OR receipt_date BETWEEN ? AND ? OR receipt_no LIKE ?)";

    $params = [ $compID, $party_id, "%$search_text%", $from_date, $to_date, "%$search_text%" ];
    $result = fetchQuery( $conn, $sql, $params );

    if ( $result[ 'status' ] === 200 ) {
        foreach ( $result[ 'data' ] as &$element ) {
            $element[ 'party_details' ] = json_decode( $element[ 'party_details' ], true );
            $element[ 'company_details' ] = json_decode( $element[ 'company_details' ], true );
        }
        $output[ 'status' ] = 200;
        $output[ 'msg' ] = 'Success';
        $output[ 'data' ] = $result[ 'data' ];
    } else {
        $output[ 'status' ] = 400;
        $output[ 'msg' ] = 'Error fetching records.';
    }
}

// Create Payin

else if ( $_SERVER[ 'REQUEST_METHOD' ] == 'POST' && isset( $obj[ 'party_id' ] ) && isset( $obj[ 'payment_method' ] ) ) {
    ob_start();
    // Start output buffering

    // Suppress error messages temporarily
    ini_set( 'display_errors', '0' );
    error_reporting( E_ALL );

    // Get parameters from the request
    $party_id = $obj[ 'party_id' ] ?? null;
    $receipt_date = $obj[ 'receipt_date' ] ?? null;
    $paid = $obj[ 'paid' ] ?? null;
    $payment_method = $obj[ 'payment_method' ] ?? null;
    // Initialize output array
    $output = [
        'status' => null,
        'msg' => null,
        'data' => null,
    ];

    // Validate required parameters
    if (
        !$party_id || !$receipt_date || !$paid || !$payment_method
    ) {
        $output[ 'status' ] = 400;
        $output[ 'msg' ] = 'Parameter MisMatch: Missing required fields.';

        exit();
    }

    // Fetch party details
    $sqlparty = 'SELECT * FROM `sales_party` WHERE `party_id` = ? AND `company_id` = ?';
    $partyResult = fetchQuery( $conn, $sqlparty, [ $party_id, $compID ] );

    // Check for errors in party details fetch
    if ( $partyResult[ 'status' ] !== 200 ) {
        $output[ 'status' ] = $partyResult[ 'status' ];
        $output[ 'msg' ] = $partyResult[ 'msg' ];

        exit();
    }

    // Check if party details are returned
    if ( empty( $partyResult[ 'data' ] ) ) {
        $output[ 'status' ] = 404;
        // Use 404 for not found
        $output[ 'msg' ] = 'Party details not found.';

        exit();
    }

    $partyDetailsJson = json_encode( $partyResult[ 'data' ][ 0 ] );

    // Fetch company details
    $companyDetailsSQL = 'SELECT * FROM `company` WHERE `company_id` = ?';
    $companyDetailsResult = fetchQuery( $conn, $companyDetailsSQL, [ $compID ] );

    // Check for errors in company details fetch
    if ( $companyDetailsResult[ 'status' ] !== 200 ) {
        $output[ 'status' ] = $companyDetailsResult[ 'status' ] ?? 500;
        $output[ 'msg' ] = $companyDetailsResult[ 'msg' ] ?? 'Unknown error while fetching company details.';

        exit();
    }

    // Check if company details are returned
    if ( empty( $companyDetailsResult[ 'data' ] ) ) {
        $output[ 'status' ] = 404;
        // Use 404 for not found
        $output[ 'msg' ] = 'Company details not found.';

        exit();
    }

    $companyDataJson = json_encode( $companyDetailsResult[ 'data' ][ 0 ] );

    // Prepare payin date
    $payinDate = date( 'Y-m-d', strtotime( $receipt_date ) );

    // Insert payin record
    $sqlReceipt = "INSERT INTO payin (company_id, party_id, party_details, receipt_date, paid, company_details, delete_at, payment_method) 
                 VALUES (?, ?, ?, ?, ?, ?, '0', ?)";

    $stmt = $conn->prepare( $sqlReceipt );
    if ( $stmt === false ) {
        $output[ 'status' ] = 500;
        $output[ 'msg' ] = 'Error preparing statement: ' . $conn->error;

        exit();
    }

    // Bind parameters
    if ($party_id && $payinDate && $paid && $companyDataJson && $payment_method) { // <-- ADDED opening brace {
        // The format string 'sssssss' is for 7 parameters.
        $stmt->bind_param( 'sssssss', $compID, $party_id, $partyDetailsJson, $payinDate, $paid, $companyDataJson, $payment_method );
    } else {
        $output[ 'status' ] = 400;
        $output[ 'msg' ] = 'One or more variables are undefined.';

        exit();
    } // <-- REMOVED the unnecessary closing brace before else

    // Execute the statement
    if ( !$stmt->execute() ) {
        $output[ 'status' ] = 500;
        $output[ 'msg' ] = 'Error executing query: ' . $stmt->error;

        exit();
    }

    // Get the inserted ID
    $id = $conn->insert_id;

    // Fetch last receipt number
    $lastReceiptSql = 'SELECT `receipt_no` FROM `payin` WHERE `company_id` = ? AND `receipt_no` IS NOT NULL ORDER BY id DESC LIMIT 1';
    $resultLastReceipt = fetchQuery( $conn, $lastReceiptSql, [ $compID ] );

    // Check for errors in fetching last receipt number
    if ( $resultLastReceipt[ 'status' ] !== 200 ) {
        $output[ 'status' ] = 500;
        $output[ 'msg' ] = 'Database query failed: ' . ( $resultLastReceipt[ 'msg' ] ?? 'Unknown error.' );
    }

    if ( empty( $resultLastReceipt[ 'data' ] ) ) {
        $output[ 'status' ] = 404;
        // Use 404 for not found
        $output[ 'msg' ] = 'No receipt number found.';
    }
    //echo 'sivamadhu';
    $invoiceNumber = $resultLastReceipt[ 'data' ][ 0 ][ 'receipt_no' ] ?? '0';

    // Fetch company prefix
    $receiptPrefixSql = 'SELECT `bill_prefix` FROM `company` WHERE `company_id` = ?';
    $resultReceiptPrefix = fetchQuery( $conn, $receiptPrefixSql, [ $compID ] );

    // Check for errors in fetching company prefix
    if ( $resultReceiptPrefix[ 'status' ] !== 200 ) {
        $output[ 'status' ] = 500;
        $output[ 'msg' ] = 'Database query failed: ' . ( $resultReceiptPrefix[ 'msg' ] ?? 'Unknown error.' );

        exit();
    }

    if ( empty( $resultReceiptPrefix[ 'data' ] ) ) {
        $output[ 'status' ] = 404;
        // Use 404 for not found
        $output[ 'msg' ] = 'No prefix found.';

        exit();
    }

    $companyPrefix = $resultReceiptPrefix[ 'data' ][ 0 ][ 'bill_prefix' ] ?? 'INV';

    // Create new receipt number
    $params = [ 'prefix_name' => $companyPrefix, 'billno' => $invoiceNumber ];
    $Receiptno = BillNoCreation::create( $params );
    // Ensure this function is defined

    // Update payin record with unique ID and receipt number
    $uniqueID = uniqueID( 'payin', $id );
    // Ensure this function is defined
    $sqlUpdate = 'UPDATE payin SET payin_id = ?, receipt_no = ? WHERE id = ? AND company_id = ?';
    $updateResult = fetchQuery( $conn, $sqlUpdate, [ $uniqueID, $Receiptno, $id, $compID ] );

    // Check for errors in updating payin record
    if ( $updateResult[ 'status' ] !== 200 ) {
        error_log( 'Error updating payin: ' . ( $updateResult[ 'msg' ] ?? 'Unknown error.' ) );
        // Log the error
        $output[ 'status' ] = 500;
        $output[ 'msg' ] = 'Database query failed: ' . ( $updateResult[ 'msg' ] ?? 'Unknown error.' );

        exit();
    }

    $output[ 'status' ] = 200;
    $output[ 'msg' ] = 'Payin Details Created Successfully';
    $output[ 'data' ] = [ 'payin_id' => $uniqueID, 'receipt_no' => $Receiptno ];

    ob_end_flush();
    // Flush output buffer
}
// Update Payin
else if ( $_SERVER[ 'REQUEST_METHOD' ] == 'PUT' ) {
    $payin_id = $obj[ 'payin_id' ];
    $party_id = $obj[ 'party_id' ];
    $receipt_date = $obj[ 'receipt_date' ];
    $paid = $obj[ 'paid' ];

    if ( !$payin_id || !$party_id || !$receipt_date || !$paid ) {
        $output[ 'status' ] = 400;
        $output[ 'msg' ] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE payin SET party_id = ?, party_details = ?, receipt_date = ?, paid = ? 
                WHERE payin_id = ? AND company_id = ?";
        $params = [ $party_id, json_encode( $party_id ), date( 'Y-m-d', strtotime( $receipt_date ) ), $paid, $payin_id, $compID ];

        $result = fetchQuery( $conn, $sql, $params );
        if ( $result[ 'status' ] === 200 ) {
            $output[ 'status' ] = 200;
            $output[ 'msg' ] = 'Payin Details Updated Successfully';
        } else {
            $output[ 'status' ] = 400;
            $output[ 'msg' ] = 'Error updating payin';
        }
    }
}

else if ( $_SERVER[ 'REQUEST_METHOD' ] == 'PUT' ) {
    
    // 1. Get the new payment_method parameter
    $payin_id = $obj[ 'payin_id' ] ?? null;
    $party_id = $obj[ 'party_id' ] ?? null;
    $receipt_date = $obj[ 'receipt_date' ] ?? null;
    $paid = $obj[ 'paid' ] ?? null;
    $payment_method = $obj[ 'payment_method' ] ?? null; 

    // 2. Update validation to include payment_method
    if ( !$payin_id || !$party_id || !$receipt_date || !$paid || !$payment_method ) { 
        $output[ 'status' ] = 400;
        $output[ 'msg' ] = 'Parameter Mismatch: Missing required fields.';
    } else {
            $sql = "UPDATE payin SET 
                        party_id = ?, 
                        party_details = ?, 
                        receipt_date = ?, 
                        paid = ?, 
                        payment_method = ? 
                    WHERE payin_id = ? AND company_id = ?";
            $params = [$party_id, json_encode( $party_id ),date( 'Y-m-d', strtotime( $receipt_date ) )$paid, $payment_method, 
                $payin_id, 
                $compID 
            ];

            $result = fetchQuery( $conn, $sql, $params );
            
            if ( $result[ 'status' ] === 200 ) {
                $output[ 'status' ] = 200;
                $output[ 'msg' ] = 'Payin Details Updated Successfully';
            } else {
                $output[ 'status' ] = 400;
                $output[ 'msg' ] = 'Error updating payin: ' . ( $result[ 'msg' ] ?? 'Unknown database error.' );
            }
        }
    }


// Delete Payin
else if ( $_SERVER[ 'REQUEST_METHOD' ] == 'DELETE' ) {
    $payin_id = $obj[ 'payin_id' ];

    if ( !$payin_id ) {
        $output[ 'status' ] = 400;
        $output[ 'msg' ] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE payin SET delete_at = '1' WHERE payin_id = ? AND company_id = ?";
        $result = fetchQuery( $conn, $sql, [ $payin_id, $compID ] );

        if ( $result[ 'status' ] === 200 ) {
            $output[ 'status' ] = 200;
            $output[ 'msg' ] = 'Payin Deleted Successfully';
        } else {
            $output[ 'status' ] = 400;
            $output[ 'msg' ] = 'Error deleting payin';
        }
    }
} else {
    $output[ 'status' ] = 400;
    $output[ 'msg' ] = 'Invalid request';
}

echo json_encode( $output, JSON_NUMERIC_CHECK );
