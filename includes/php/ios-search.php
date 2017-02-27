<?php
	include 'SearchAnalytics.php';

$terms = filter_input(INPUT_POST, 'terms');
$location = filter_input(INPUT_POST, 'location');
$GLOBALS['limit'] = "10"; // filter_input(INPUT_POST, 'limit');
$page = filter_input(INPUT_POST, 'page');
$GLOBALS['offset'] = "0"; // filter_input(INPUT_POST, 'offset');
$distance = filter_input(INPUT_POST, 'distance');
$type = "Mobile";
$platform = "iOS";

if (isset($terms)) {
    getResults($location, $distance, $terms);
}

$GLOBALS['lat'] = '';
$GLOBALS['lng'] = '';


$search_analytics = new SearchAnalytics();
$search_analytics->save_search_terms($terms);
$search_analytics->save_search_general_information($location, $distance, $terms, $type, $platform);

/*
 * Get the location based on either the city/state or the zip code given.
 * The application will automatically choose the location based on the user's IP address.
 * The user can also choose to update this information if they want, so it is necessary
 * to check and see what information has been given.
 * 
 * @params String location         Either zip code or city/state combination
 * @return array data tables       Maximum of three data tables
 */

function getDataTables($location, $distance) {
    // Include the database connection
    include 'DbConnection.php';

    $GLOBALS['distance'] = $distance;

    // First determine if zip or city/state was given
    $length = strlen($location);
    if ($length == 5 && preg_match('|[0-9]|', $location)) {
        $json = file_get_contents("https://maps.googleapis.com/maps/api/geocode/json?address=$location&key=AIzaSyDo0e6jGh6cZhToU-XRWeRHCKezjLT9_Ko");
        $obj = json_decode($json, true);
        $lat = $obj['results'][0]['geometry']['location']['lat'];
        $lng = $obj['results'][0]['geometry']['location']['lng'];
        $GLOBALS['lat'] = $lat;
        $GLOBALS['lng'] = $lng;
    } else {
        $location = explode(',', str_replace(' ', ' ', $location));
        $loc = str_replace(' ', '+', implode($location));
        $json = file_get_contents("https://maps.googleapis.com/maps/api/geocode/json?address=$loc&key=AIzaSyDo0e6jGh6cZhToU-XRWeRHCKezjLT9_Ko");
        $obj = json_decode($json, true);
        $lat = $obj['results'][0]['geometry']['location']['lat'];
        $lng = $obj['results'][0]['geometry']['location']['lng'];
        $GLOBALS['lat'] = $lat;
        $GLOBALS['lng'] = $lng;
    }


    // Get the closest city within x miles of the location

    $sql = "SELECT DISTINCT DATA_TABLE FROM zips WHERE (ACOS(SIN(radians($lat))*SIN(radians(LATITUDE))+ COS(radians($lat))*COS(radians(LATITUDE))*COS(radians(LONGITUDE)-radians($lng)))*3443.89849)<'$distance' limit 3";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $num_rows = $stmt->rowCount();
    $data_tables = array(
        '1' => 'blank_customer',
        '2' => 'blank_customer',
        '3' => 'blank_customer'
    );


    if ($num_rows > 0) {
        $count = 1;
        $num_rows += 1;
        while ($count < $num_rows) {
            $dt_results = $stmt->fetch();
            $data_tables[$count] = strtolower($dt_results['DATA_TABLE']);
            $count++;
        }
    }
    return $data_tables;
}

/*
 * This is the primary function of this file. 
 * This function will parse the search terms and return results.
 * 
 * @return json array results   SQL results of the search based on the user's location and inputs
 */

function getResults($location, $distance, $terms) {
    include 'DbConnection.php';
    // Get the datatables to be used for the search. 
    $data_tables = getDataTables($location, $distance);

    // Set the latitude, longidute, and distance variables
    $lat = $GLOBALS['lat'];
    $lng = $GLOBALS['lng'];
    $distance = $GLOBALS['distance'];
    $limit = $GLOBALS['limit'];
    $offset = $GLOBALS['offset'];

    // Set the datatable names
    if ($data_tables[1] != 'blank_customer') {
        $customer_one = $data_tables[1] . '_customer';
        $items_one = $data_tables[1] . '_items';
        $datatable_one = $data_tables[1];
    }

    if ($data_tables[2] != 'blank_customer') {
        $customer_two = $data_tables[2] . '_customer';
        $items_two = $data_tables[2] . '_items';
        $datatable_two = $data_tables[2];
    }

    if ($data_tables[3] != 'blank_customer') {
        $customer_three = $data_tables[3] . '_customer';
        $items_three = $data_tables[3] . '_items';
        $datatable_three = $data_tables[3];
    }


    // Remove all unwanted words from the search terms
    $pattern = "/\band|with|that|this|or|because|of| |\b/i";
    $sterile_terms = explode(" ", preg_replace($pattern, '/', $terms));

    foreach ($sterile_terms as $sterilized) {
        $sterilized_string .= $sterilized;
    }

    $search_terms = array_diff(explode('/', $sterilized_string), ['']);


    $parameters = "$items_one.ITEM_NAME = '$search_terms[1]' OR $items_one.ITEM_DESCRIPTION = '$search_terms[1]' OR $customer_one.COMPANY_NAME = '$search_terms[1]' OR $customer_one.KEYWORDS = '$search_terms[1]'";

    foreach ($search_terms as $terms) {
        $parameters .= " OR $items_one.ITEM_DESCRIPTION LIKE '%" . $terms . "%' OR $items_one.ITEM_NAME LIKE '%" . $terms . "%' OR $customer_one.KEYWORDS LIKE '%" . $terms . "%' OR $customer_one.COMPANY_NAME LIKE '%" . $terms . "%' ";
    }


    $sql = "SELECT $items_one.ID AS 'ITEM_ID', $customer_one.KEYWORDS AS 'KEYWORDS',$customer_one.LATITUDE AS 'LATITUDE', $customer_one.LONGITUDE AS 'LONGITUDE', $items_one.ID AS 'COMPANY_ID', $customer_one.COMPANY_NAME AS 'COMPANY', if($customer_one.SECONDARY_ADDRESS != '', CONCAT($customer_one.PRIMARY_ADDRESS, '\n', $customer_one.SECONDARY_ADDRESS), $customer_one.PRIMARY_ADDRESS) as 'ADDRESS', $customer_one.city as 'CITY', $customer_one.state AS 'STATE', $customer_one.zip AS 'ZIP', $items_one.ITEM_NAME AS 'NAME', $items_one.ITEM_RANK AS 'ITEM_RANK', $items_one.ITEM_DESCRIPTION AS 'DESCRIPTION', $items_one.ITEM_IMAGE AS 'PHOTO_DIRECTORY', $items_one.ID AS 'ID',  IF($customer_one.PRIMARY_PHONE != '', $customer_one.PRIMARY_PHONE, '') AS 'PHONE', $customer_one.COMPANY_URL AS 'LINK',(ACOS(SIN(radians($lat))*SIN(radians($customer_one.LATITUDE))+ COS(radians($lat))*COS(radians($customer_one.LATITUDE))*COS(radians($customer_one.LONGITUDE)-radians($lng)))*3443.89849) AS 'DISTANCE' FROM $items_one JOIN $customer_one ON $customer_one.ID = $items_one.ID WHERE ($parameters) AND ((ACOS(SIN(radians($lat))*SIN(radians($customer_one.LATITUDE))+ COS(radians($lat))*COS(radians($customer_one.LATITUDE))*COS(radians($customer_one.LONGITUDE)-radians($lng)))*3443.89849)<'10')";
    if ($data_tables[2] == 'blank_customer') {
        $sql .= " LIMIT $limit OFFSET $offset";
    }
    if ($data_tables[2] != 'blank_customer') {
        $sql .= " UNION ALL SELECT $items_two.ID AS 'ITEM_ID', $customer_two.KEYWORDS AS 'KEYWORDS',$customer_two.LATITUDE AS 'LATITUDE', $customer_two.LONGITUDE AS 'LONGITUDE', $items_two.ID AS 'COMPANY_ID', $customer_two.COMPANY_NAME AS 'COMPANY', if($customer_two.SECONDARY_ADDRESS != '', CONCAT($customer_two.PRIMARY_ADDRESS, '\n', $customer_two.SECONDARY_ADDRESS), $customer_two.PRIMARY_ADDRESS) as 'ADDRESS', $customer_two.city as 'CITY', $customer_two.state AS 'STATE', $customer_two.zip AS 'ZIP', $items_two.ITEM_NAME AS 'NAME', $items_two.ITEM_RANK AS 'ITEM_RANK', $items_two.ITEM_DESCRIPTION AS 'DESCRIPTION', $items_two.ITEM_IMAGE AS 'PHOTO_DIRECTORY', $items_two.ID AS 'ID', IF($customer_two.PRIMARY_PHONE != '', $customer_two.PRIMARY_PHONE, '') AS 'PHONE', $customer_two.COMPANY_URL AS 'LINK',(ACOS(SIN(radians($lat))*SIN(radians($customer_two.LATITUDE))+ COS(radians($lat))*COS(radians($customer_two.LATITUDE))*COS(radians($customer_two.LONGITUDE)-radians($lng)))*3443.89849) AS 'DISTANCE' FROM $items_two JOIN $customer_two ON $customer_two.ID = $items_two.ID WHERE ($parameters) AND (ACOS(SIN(radians($customer_two.LATITUDE))*SIN(radians($lat))+ COS(radians($customer_two.LATITUDE))*COS(radians($lat))*COS(radians($customer_two.LONGITUDE)-radians($lng))*3443.89849)<'10')";
    }
    if ($data_tables[2] != 'blank_customer' && $data_tables[3] == 'blank_customer') {
        $sql .= " LIMIT $limit OFFSET $offset";
    }
    if ($data_tables[3] != 'blank_customer') {
        $sql .= " UNION ALL SELECT $items_three.ID AS 'ITEM_ID', $customer_three.KEYWORDS AS 'KEYWORDS',$customer_three.LATITUDE AS 'LATITUDE', $customer_three.LONGITUDE AS 'LONGITUDE', $items_three.ID AS 'COMPANY_ID', $customer_three.COMPANY_NAME AS 'COMPANY', if($customer_three.SECONDARY_ADDRESS != '', CONCAT($customer_three.PRIMARY_ADDRESS, '\n', $customer_three.SECONDARY_ADDRESS), $customer_three.PRIMARY_ADDRESS) as 'ADDRESS', $customer_three.city as 'CITY', $customer_three.state AS 'STATE', $customer_three.zip AS 'ZIP', $items_three.ITEM_RANK AS 'ITEM_RANK', $items_three.ITEM_NAME AS 'NAME', $items_three.ITEM_DESCRIPTION AS 'DESCRIPTION', $items_three.ITEM_IMAGE AS 'PHOTO_DIRECTORY', $items_three.ID AS 'ID' , IF($customer_three.PRIMARY_PHONE != '', $customer_three.PRIMARY_PHONE, '') AS 'PHONE', $customer_three.COMPANY_URL AS 'LINK',(ACOS(SIN(radians($lat))*SIN(radians($customer_three.LATITUDE))+ COS(radians($lat))*COS(radians($customer_three.LATITUDE))*COS(radians($customer_three.LONGITUDE)-radians($lng)))*3443.89849) AS 'DISTANCE' FROM $items_three JOIN $customer_three ON $customer_three.ID = $items_three.ID WHERE ($parameters) AND (ACOS(SIN(radians($customer_three.LATITUDE))*SIN(radians($lat))+ COS(radians($customer_three.LATITUDE))*COS(radians($lat))*COS(radians($customer_three.LONGITUDE)-radians($lng))*3443.89849)<'10') LIMIT $limit OFFSET $offset";
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $num_rows = $stmt->rowCount();


    if ($num_rows > 0) {
        $results = $stmt->fetchAll();

        // Calculate the distance to each vendor from the applied location
        foreach ($results as &$result) {
            $result['tier'] = (ACOS(SIN(deg2rad($result['LATITUDE'])) * SIN(deg2rad($lat)) + COS(deg2rad($result['LATITUDE'])) * COS(deg2rad($lat)) * COS(deg2rad($lng) - deg2rad($result['LONGITUDE']))) * 3443.89849);
            $result['vendor_distance'] = number_format($result['tier'], 1) . "mi.";
        }

        $num_words = count($search_terms);

        foreach ($results as &$result) {
            $result['rank'] = 100;
            $key_words = " " . preg_replace('/\s+/', '^', preg_replace('/[[:punct:]]/', ' ', strtoupper(' ' . $result['KEYWORDS'] . ' ')));
            $item_description = " " . preg_replace('/\s+/', '^', preg_replace('/[[:punct:]]/', ' ', strtoupper(' ' . $result['DESCRIPTION'] . ' ')));
            $customer_name = " " . preg_replace('/\s+/', '^', preg_replace('/[[:punct:]]/', ' ', strtoupper(' ' . $result['COMPANY'] . ' ')));
            $item_name = " " . preg_replace('/\s+/', '^', preg_replace('/[[:punct:]]/', ' ', strtoupper(' ' . $result['NAME'] . ' ')));
            $counter = 0;
            $name_match = 0;
            while ($counter < $num_words) {
                $search_word = "^" . $search_terms[++$counter] . "^";
                // Ranks the word based on number of matches with the name
                if (strpos($item_name, strtoupper($search_word)) != false) {
                    $name_match += 1;

                    //Adjust the ranking accordingly
                    if ($name_match > 1) {
                        $result['rank'] = $result['rank'] - (10 * $name_match);
                    } else {
                        $result['rank'] = $result['rank'] - (5 * $name_match);
                    }
                }

                // Ranks based on number of matches with the description
                if (strpos($item_description, $search_word) != false) {
                    $name_match += 1;

                    //Adjust the ranking accordingly
                    if ($name_match > 1) {
                        $result['rank'] = $result['rank'] - 5;
                    }
                }

                // Ranks based on number of matches with the company name
                if (strpos($customer_name, $search_word) != false) {
                    $name_match += 1;

                    //Adjust the ranking accordingly
                    if ($name_match > 1) {
                        $result['rank'] = $result['rank'] - 3;
                    }
                }

                // Ranks based on number of matches with the keywords
                if (strpos($key_words, $search_word) != false) {
                    $name_match += 1;

                    //Adjust the ranking accordingly
                    if ($name_match > 1) {
                        $result['rank'] = $result['rank'] - 2;
                    }
                }
                $counter++;
            }
        }
        usort($results, 'rankItems');
        $counter = 0;
        $ios_results = array();
        foreach ($results as &$result) {
            $image = $result['PHOTO_DIRECTORY'];
            if(strpos($image, "http") != false) {
                $result['image_url'] = $result['PHOTO_DIRECTORY'];
            } else {
                $result['image_url'] = "https://www.utterfare.com/290sc_images/emptyplate.png";
            }
            array_push($ios_results, $result);
        }
        echo json_encode($ios_results);
    } else {
        echo json_encode("No Results");
    }
}

function rankItems($a, $b) {
    $retval = strnatcmp($a['rank'], $b['rank']);
    return $retval;
}
