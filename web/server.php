<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';

// check mandatory $_GET parameters
if (
  !isset($_GET['center']) ||
  !isset($_GET['southWest']) ||
  !isset($_GET['northEast']) ||
  !isset($_GET['gender']) ||
  !isset($_GET['age']) ||
  !isset($_GET['travelmode'])
) {
  echo "malformed request";
  exit();
}


// load env
function loadEnv($filePath)
{
    if (!file_exists($filePath)) { return; }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Ignore comments
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
// Load the .env file
loadEnv(__DIR__ . '/.env');


// token saving measures
function token_saving($str) {
  $str = str_replace("===\n", '', $str);
  $str = str_replace("---\n", '', $str);
  $str = str_replace("\n\n", "\n", $str);
  $str = rtrim($str, "\n");
  return $str;
}

// gpt-4
function convertToDMS($latitude, $longitude) {
    $latDirection = $latitude < 0 ? 'S' : 'N';
    $lngDirection = $longitude < 0 ? 'W' : 'E';
    $latitude = abs($latitude);
    $longitude = abs($longitude);
    $latDegrees = floor($latitude);
    $latMinutes = floor(($latitude - $latDegrees) * 60);
    $latSeconds = round(($latitude - $latDegrees - $latMinutes/60) * 3600, 4);
    $lngDegrees = floor($longitude);
    $lngMinutes = floor(($longitude - $lngDegrees) * 60);
    $lngSeconds = round(($longitude - $lngDegrees - $lngMinutes/60) * 3600, 4);
    $result = $latDegrees.'°'.$latMinutes."'".round(floatval($latSeconds), 2).'"'."".$latDirection.", ".
              $lngDegrees.'°'.$lngMinutes."'".round(floatval($lngSeconds), 2).'"'."".$lngDirection;
    return $result;
} // convertToDMS

// 62.89012166642847,27.67159665673547
$center = explode(',', $_GET['center']);
// 62.889782527028615,27.67233597391968
$southWest = explode(',', $_GET['southWest']);
// 62.890460805828326,27.673814608288104
$northEast = explode(',', $_GET['northEast']);

$gender = $_GET['gender'];
$age = intval($_GET['age']);
$travelmode = $_GET['travelmode'];

$radius = '75';
if (isset($_GET['radius'])) {
  $radius = intval($_GET['radius']);
}

$cachestring = $center[0].','.$center[1].'-'.$radius . '-'.$gender.'-'.$age.'-'.$travelmode;

date_default_timezone_set('Europe/Helsinki');
if (isset($_GET['datetime'])) {
  $datetime = urldecode($_GET['datetime']);
} else {
  // Sunday, Aug 13, 2023, 2:20 PM
  $datetime = date("l, M d, Y, g:i A");
}

$DMScoords = convertToDMS($center[0], $center[1]);

// gpt-4
// function reverseGeocode($lat, $lon) {
//     // URL of the Nominatim API
//     $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lon}";
//     $ch = curl_init($url);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//     $response = curl_exec($ch);
//     if ($response === false) {
//         die('Error occurred: ' . curl_error($ch));
//     }
//     curl_close($ch);
//     $data = json_decode($response, true);
//     if (!isset($data['address'])) {
//         return false;
//         // die('No address found for the provided coordinates');
//     }
//     return $data['address'];
// } // reverseGeocode

// gpt-4
function queryOverpass($overpassQuery, $cachefile) {
  // Define base URL for Overpass API
  $overpassApiBaseUrl = 'https://overpass-api.de/api/interpreter?data=';

  // Encode the query
  $overpassQueryEncoded = urlencode($overpassQuery);

  // cache check
  if (file_exists($cachefile)) {
    // Read the JSON file and decode the JSON file
    $cached = json_decode(file_get_contents($cachefile), true);
    // indicate that this query was cached
    $cached['cached'] = true;
    return $cached;
  }

  // Combine the base URL with the encoded query
  $url = $overpassApiBaseUrl . $overpassQueryEncoded;
  $url .= '&ts=' . time(); // cahcebuster

  // Initialize a CURL session
  $ch = curl_init();
  // Set the options for the CURL session
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
  curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36');
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cache-Control: no-cache"));
  // Execute the CURL session and get the response
  $response = curl_exec($ch);
  $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  if ($httpcode != 200) {
    throw new Exception('HTTP response code not expected: ' . $httpcode);
  }

  // If there was an error, throw an Exception
  if (curl_errno($ch)) {
      throw new Exception(curl_error($ch));
  }
  // Close the CURL session
  curl_close($ch);

  // Return the response
  if (empty($response)) {
    throw new Exception('Response is empty');
  }
  $json = json_decode($response, true);
  if (json_last_error() != JSON_ERROR_NONE) {
    var_dump($response);
    throw new Exception('Invalid JSON: ' . json_last_error_msg());
  }

  // write cache
  file_put_contents($cachefile, json_encode($json));

  // indicate that this query was not cached
  $json['cached'] = false;

  return $json;
} // queryOverpass

function getPOIsInRectangle($center, $southWest, $northEast) {
  global $radius, $cachestring;

  // Create Overpass QL query
  $queries = [
//        node(around:'.$radius.','.$center[0].','.$center[1].')[~"."~"."];
//        way(around:'.$radius.','.$center[0].','.$center[1].')[~"."~"."];
//        is_in('.$center[0].','.$center[1].')->.a;
    '
      [out:json];
      (
        node('.implode(',', $southWest).','.implode(',', $northEast).')[~"."~"."];
      );
      out body;
      >;
      out skel qt;
    ',
    '
      [out:json];
      (
        way('.implode(',', $southWest).','.implode(',', $northEast).')[~"."~"."];
      );
      out body;
      >;
      out skel qt;
    ',
    '
      [out:json];
      (
        is_in('.$center[0].','.$center[1].')->.a;
        area.a[~"."~"."];
      );
      out body;
      >;
      out skel qt;
    '
  ];
      // node(' . $southWest[0] . ',' . $southWest[1] . ',' . $northEast[0] . ',' . $northEast[1] . ');
      // is_in(' . $center[0] . ',' . $center[1] . ')->.a;
      // area.a;

  $qry_results = [];
  foreach($queries as $qnum => $qry) {
    $qkey = [
      'node',
      'way',
      'is_in'
    ];
    $cachefile = './cache/overpass/' . $cachestring . '-' . $qkey[$qnum] . '.json';
    $json = queryOverpass($qry, $cachefile);
    if (!isset($json['elements'])) {
      continue;
    }
    $qry_results = array_merge($qry_results, $json['elements']);
  }
  return $qry_results;
} // getPOIsInRectangle


// gpt-4
function calculateDistance($point1, $point2) {
    $earthRadius = 6371000; // Earth's radius in meters
    $lat1 = deg2rad($point1[0]);
    $lon1 = deg2rad($point1[1]);
    $lat2 = deg2rad($point2[0]);
    $lon2 = deg2rad($point2[1]);
    $deltaLat = $lat2 - $lat1;
    $deltaLon = $lon2 - $lon1;
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
        cos($lat1) * cos($lat2) *
        sin($deltaLon / 2) * sin($deltaLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance = $earthRadius * $c;
    return $distance;
} // calculateDistance

// gpt-4
function filterPOIs($json) {
    $nodes = [];
    foreach ($json as $element) {
        if (!isset($element['tags'])) {
          continue;
        }
        if (isset($element['id'])) {
          unset($element['id']);
        }
        $nodes[] = $element;
    }
    return $nodes;
} // filterPOIs

// gpt-4
function sortPoiStringByDistance($str) {
    // Split the string into an array of lines
    $lines = explode("\n", $str);
    // Sort the array using a custom comparison function
    usort($lines, function ($a, $b) {
        // Extract the distance from each line
        preg_match('/\((\d+)m\)/', $a, $matchesA);
        preg_match('/\((\d+)m\)/', $b, $matchesB);
        $distanceA = isset($matchesA[1]) ? (int)$matchesA[1] : PHP_INT_MAX;
        $distanceB = isset($matchesB[1]) ? (int)$matchesB[1] : PHP_INT_MAX;
        // Compare the distances
        return $distanceA - $distanceB;
    });
    // Join the sorted array back into a string and return it
    return implode("\n", $lines);
} // sortPoiStringByDistance

function formatPOIs($json, $center) {
    global $radius;
    $poi_nodes = [];
    $other = [];
    foreach ($json as $element) {
      if (!isset($element['tags'])) {
          continue;
        }
      $tags = $element['tags'];
      // skip boundary:landarea, e.g.Balkan Peninsula
      if (isset($tags['type']) && $tags['type'] === 'boundary') {
        continue;
      }
      $description = getHumanReadableDescription($tags);
      if ($description) {
        // distance calculation
        // does the element contain lat/lon?
        if (isset($element['lat']) && isset($element['lon'])) {
          $distance = calculateDistance($center, [$element['lat'], $element['lon']]);
          $description = $description . ' (' . round($distance) . 'm)';
          $poi_nodes[] = $description;
        } else {
          $other[] = $description;
        }
      } 
    }
    $poi_str = '';
    foreach($poi_nodes as $poi) {
      if ($poi) {
        $poi_str = '- ' . $poi . "\n" . $poi_str;
      }
    }
    if ($other) {
      $poi_str = trim($poi_str, "\n ");
      $grammar = 'is';
      if (count($other) > 1) {
        $grammar = 'are';
      }
      $poi_str .= "\n\nWithin a ".$radius."m radius, there ".$grammar." also:";
      // combine the street addresses into one line
      // $streets = [];
      // $streetkeys = [];
      // foreach($other as $key => $other_poi) {
      //   if ($other_poi === '') continue;
      //   $streetkeys[] = $key;
      //   $streetwithoutnumber = preg_replace('/^\d*\s/i', '', $other_poi);
      //   preg_match('/^(\d*)\s/i', $other_poi, $matches);
      //   $housenumer = false;
      //   if ($matches) {
      //     $housenumer = trim($matches[0], ' ');
      //   }
      //   if (!$housenumer) continue;
      //   $streets[$streetwithoutnumber][] = $housenumer;
      // }
      // foreach ($streets as $key => $s) {
      //   sort($streets[$key]);
      // }
      // $outstreets = [];
      // foreach ($streets as $key => $s) {
      //   if(count($streets[$key]) > 1) {
      //     $streets[$key] = min($streets[$key]) . '-' . max($streets[$key]);
      //   } else {
      //     $streets[$key] = $streets[$key][0];
      //   }
      //   $outstreets[] = $streets[$key] . ' ' . $key;
      // }
      // foreach($streetkeys as $key) {
      //   unset($other[$key]);
      // }
      // if (count($outstreets) > 1) {
      //   $other = array_merge($other, $outstreets);
      // }
      foreach($other as $other_poi) {
        if ($other_poi != '') {
          // var_dump($other_poi);
          $poi_str = $poi_str . "\n- " . $other_poi;
        }
      }
    }
    $poi_str = trim($poi_str, "\n ");
    // sort lines by distance
    $poi_str = sortPoiStringByDistance($poi_str);

    return $poi_str;
} // formatPOIs

function addRoadInfo($description, &$tags) {
    if ($tags['highway'] !== 'residential') {
      $tags['highway'] = '';
    }
    if (isset($tags['oneway'])) {
      if ($tags['oneway'] === 'yes') {
        $tags['highway'] .= ' oneway';
      } else {
        $tags['highway'] .= ' twoway';
      }
      unset($tags['oneway']);
    }
    if (isset($tags['lanes'])) {
      $tags['highway'] .= ' ' . $tags['lanes'] . '-lane';
      unset($tags['lanes']);
    }
    if (isset($tags['surface'])) {
      $tags['highway'] .= ' ' . $tags['surface'];
      unset($tags['surface']);
    }
    $tags['highway'] = trim($tags['highway'], ' ');
    $lit = '';
    if (isset($tags['lit'])) {
      if ($tags['lit'] === 'yes') {
        $lit = ', lit'; // with lighting
      } else {
        $lit = ', unlit'; // without lighting
      }
      unset($tags['lit']);
    }
    $type = 'road';
    if (isset($tags['bridge'])) {
      if ($tags['bridge'] === 'yes') {
        $type = 'bridge';
      }
      unset($tags['bridge']);
    }
    $description .= ' (' . $tags['highway'] . ' ' . $type . $lit . ')';
    unset($tags['highway']);
    return $description;
} // addRoadInfo

function residentialAddress(&$tags) {
  $address = '';
  if (isset($tags['addr:housenumber'])) {
    $address .= $tags['addr:housenumber'] . ' ';
    unset($tags['addr:housenumber']);
  }
  if (isset($tags['addr:street'])) {
    $address .= $tags['addr:street'] . ', ';
    unset($tags['addr:street']);
  }
  if (isset($tags['addr:postcode'])) {
    $address .= $tags['addr:postcode'] . ' ';
    unset($tags['addr:postcode']);
  }
  if (isset($tags['addr:city'])) {
    $address .= $tags['addr:city'];
    unset($tags['addr:city']);
  }
  $address = rtrim($address, ', ');
  return $address;
} // residentialAddress

function getHumanReadableDescription($tags) {
    // var_dump($tags);
    $description = [];
    // name (prefer English name)
    $filters = ['addr:country', 'opening_hours:covid19', 'source', 'addr:interpolation'];
    foreach ($filters as $f) {
      if (isset($tags[$f])) {
        unset($tags[$f]);
      }
    }
    // residential address?
    if (isset($tags['addr:city'])) {
        $description[] = residentialAddress($tags);
    } elseif (isset($tags['int_name'])) {
      if (isset($tags['highway'])) {
        $description[] = addRoadInfo($tags['int_name'], $tags);
      } else {
        $description[] = $tags['int_name'];
      }
      unset($tags['destination:street']);
      unset($tags['int_name']);
      unset($tags['name:en']);
      unset($tags['name']);
    } elseif (isset($tags['name:en'])) {
      if (isset($tags['highway'])) {
        $description[] = addRoadInfo($tags['name:en'], $tags);
      } else {
        $description[] = $tags['name:en'];
      }
      unset($tags['destination:street']);
      unset($tags['name:en']);
      unset($tags['name']);
    } elseif (isset($tags['name'])) {
      if (isset($tags['highway'])) {
        $description[] = addRoadInfo($tags['name'], $tags);
      } else {
        $description[] = $tags['name'];
      }
      unset($tags['destination:street']);
      unset($tags['name']);
    } elseif (isset($tags['destination:street'])) {
      if (isset($tags['highway'])) {
        $description[] = addRoadInfo($tags['destination:street'], $tags);
      } else {
        $description[] = $tags['destination:street'];
      }
      unset($tags['destination:street']);
    }

    if (isset($tags['maxspeed'])) {
      $tags['maxspeed'] .= 'km/h';
    }

    // alt name (prefer English name)
    if (isset($tags['alt_name:en'])) {
        $description[] = '(' . $tags['alt_name:en'] . ')';
        unset($tags['alt_name:en']);
        unset($tags['alt_name']);
    } elseif (isset($tags['alt_name'])) {
        $description[] = '(' . $tags['alt_name'] . ')';
        unset($tags['alt_name']);
    }
    if (isset($tags['description'])) {
        $description[] = $tags['description'];
        unset($tags['description']);
    }
    // wikipedia entry?
    if (isset($tags['wikipedia'])) {
        // convert to wikipedia link
        $wikipedia = explode(':', $tags['wikipedia']);
        if (count($wikipedia) === 2) {
          // if ($wikipedia[0] == 'en') {
            $description[] = 'See Wikipedia: https://'.$wikipedia[0].'.wikipedia.org/wiki/' . $wikipedia[1];
          // }
        } else {
          $description[] = 'See Wikipedia: ' . $tags['wikipedia'];
        }
        unset($tags['wikipedia']);
        // other wikipedia links?
        $delete = [];
        foreach ($tags as $k => $v) {
          if (str_starts_with($k, 'wikipedia')) {
            $delete[] = $k;
          }
        }
        foreach ($delete as $d) {
          unset($tags[$d]);
        }
    }

    if (isset($tags['amenity'])) {
      $type = $tags['amenity'];
      if (str_contains($type, '_')) {
        $type = str_replace('_', ' ', $type);
      }
      $description[] = $type;
      unset($tags['amenity']);
    }
    if (isset($tags['highway'])) {
      $type = $tags['highway'];
      if (str_contains($type, '_')) {
        $type = str_replace('_', ' ', $type);
      }
      $description[] = $type;
      unset($tags['highway']);
    }
    if (isset($tags['barrier']) && $tags['barrier'] === 'turnstile') {
      $description[] = 'turnstile barrier';
      unset($tags['barrier']);
    }
    if (isset($tags['railway']) && $tags['railway'] === 'subway_entrance') {
      $type = '';
      if (isset($tags['wheelchair']) && $tags['wheelchair'] === 'yes') {
        $type = ' (wheelchair accessible)';
        unset($tags['wheelchair']);
      }
      $description[] = 'subway entrance'.$type;
      unset($tags['railway']);
      unset($tags['entrance']);
    }
    if (isset($tags['crossing'])) {
      $description[] = $tags['crossing'];
      unset($tags['crossing']);
    }
    if (isset($tags['emergency']) && $tags['emergency'] === 'fire_hydrant') {
      $type = '';
      if (isset($tags['fire_hydrant:type'])) {
        $type = ' ('.$tags['fire_hydrant:type'].')';
        unset($tags['fire_hydrant:type']);
      }
      $description[] = 'fire hydrant'.$type;
      unset($tags['emergency']);
    }
    if (isset($tags['tourism']) && $tags['tourism'] === 'information') {
      $type = '';
      if (isset($tags['map_type'])) {
        $type = ' '.$tags['map_type'];
        unset($tags['map_type']);
      }
      if (isset($tags['information']) && $tags['information'] === 'map') {
        $type = ' map';
        unset($tags['information']);
      }
      $description[] = 'tourism information'.$type;
      unset($tags['map_size']);
      unset($tags['tourism']);
      unset($tags['operator']);
    }

    // if (isset($tags['barrier']) && $tags['barrier'] === 'gate') {
    //   $type = 'gate';
    //   if (isset($tags['access'])) {
    //     $type = $tags['access'].' access '.$type;
    //     unset($tags['access']);
    //   }
    //   $description[] = $type;
    //   unset($tags['barrier']);
    // }

    // if (isset($tags['barrier']) && $tags['barrier'] === 'guard_rail') {
    //   unset($tags['barrier']);
    // }

    // remove unused tags
    unset($tags['wikidata']);
    // unset($tags['wheelchair']);
    unset($tags['blood:plasma']);
    unset($tags['blood:platelets']);
    unset($tags['donation:compensation']);
    unset($tags['blood:whole']);
    unset($tags['local_ref']);
    unset($tags['mapillary']);
    unset($tags['survey:date']);
    unset($tags['brand:ja']);
    unset($tags['official_name:ja']);
    unset($tags['landuse']);
    // unset($tags['leisure']);
    unset($tags['building']); // building: yes
    // unset($tags['type']);
    unset($tags['turn:lanes']);
    unset($tags['boundary']);
    unset($tags['natural']);
    unset($tags['layer']);
    unset($tags['parking:lane:both']);
    unset($tags['source:maxspeed']);
    unset($tags['second_hand']);
    unset($tags['tactile_paving']);
    unset($tags['check_date']);
    unset($tags['foot']);
    unset($tags['note']);
    unset($tags['traffic_signals:direction']);
    unset($tags['fire_hydrant:position']);
    unset($tags['crossing:markings']);
    unset($tags['crossing:island']);
    unset($tags['fire_hydrant:diameter']);
    unset($tags['water_source']);
    unset($tags['currency:PLN']);
    unset($tags['operator:wikipedia']);
    unset($tags['network:wikipedia']);
    unset($tags['payment:coins']);
    unset($tags['payment:visa_electron']);
    unset($tags['payment:v_pay']);
    unset($tags['payment:notes']);
    unset($tags['ref']);
    unset($tags['network:wikidata']);
    unset($tags['operator:wikidata']);
    unset($tags['ref:csioz']);
    unset($tags['payment:contactless']);
    unset($tags['payment:electronic_purses']);
    unset($tags['brand:wikipedia']);
    unset($tags['brand:wikidata']);
    unset($tags['crossing:end']);
    unset($tags['cash_in']);
    unset($tags['url']);

    // accessibility related
    unset($tags['kerb']);
    if (isset($tags['barrier']) && $tags['barrier'] === 'kerb') {
      unset($tags['barrier']);
    }
    unset($tags['tactile_paving:slab_size']);

    // add all the rest
    foreach ($tags as $k => $v) {
        $description[] = $k . ': ' . $v;
    }

    return implode(', ', $description);
} // getHumanReadableDescription

function getAdminLevels(&$res) {
  $admin_levels = [];
  if (!is_array($res)) {
    return $admin_levels;
  }
  $deletekeys = [];
  foreach ($res as $key => $el) {
    if ( !isset($el['tags']) || !isset($el['tags']['admin_level'])) {
      continue;
    }
    $deletekeys[] = $key;
    $out = [];
    $level = $el['tags']['admin_level'];
    $tags = $el['tags'];
    $includetags = [ 'name', 'name:en', 'official_name', 'official_name:en', 'population', 'wikidata', 'wikipedia' ];
    foreach($includetags as $tag) {
      if (isset($tags[$tag])) {
        $out[$tag] = $tags[$tag];
      }
    }
    $admin_levels[ $level ] = $out;
  }
  // remove the admin levels from further POI processing
  foreach($deletekeys as $key) {
    unset($res[$key]);
  }
  return $admin_levels;
} // getAdminLevels


function cleaner(&$response) {
  $nodeskeys = [];
  $tagstoremove = [];
  foreach ($response as $key => $val) {
    if (isset($val['tags'])) {
      // remove some of the extra language tags
      foreach ($val['tags'] as $k => $v) {
        if (str_starts_with($k, 'alt_name')) {
          if (!in_array($k, ['alt_name', 'alt_name:en'])) {
            $tagstoremove[] = ['outerkey' => $key, 'innerkey' => $k];
          }
        }
        if (str_starts_with($k, 'name')) {
          if (!in_array($k, ['name', 'name:en'])) {
            $tagstoremove[] = ['outerkey' => $key, 'innerkey' => $k];
          }
        }
      }
    }
    if (isset($val['nodes'])) {
      $nodeskeys[] = $key;
    }
  }
  // filter out the nodes
  foreach ($nodeskeys as $key) {
    unset($response[$key]['nodes']);
  }
  foreach ($tagstoremove as $o) {
    unset($response[$o['outerkey']]['tags'][$o['innerkey']]);
  }
} // cleaner

function getNominatim($center) {
  $cachefile = './cache/nominatim/' . $center[0] . ',' . $center[1] . '.json';
  $url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat='.$center[0].'&lon='.$center[1].'&zoom=18&addressdetails=1&accept-language=en';
  // var_dump($url);
  // Initialize a CURL session
  $ch = curl_init();
  // Set the options for the CURL session
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
  curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36');
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cache-Control: no-cache"));
  // Execute the CURL session and get the response
  $response = curl_exec($ch);
  $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($httpcode != 200) {
    throw new Exception('HTTP response code not expected: ' . $httpcode);
  }
  // If there was an error, throw an Exception
  if (curl_errno($ch)) {
      throw new Exception(curl_error($ch));
  }
  // Close the CURL session
  curl_close($ch);
  // Return the response
  if (empty($response)) {
    throw new Exception('Response is empty');
  }
  $json = json_decode($response, true);
  if (json_last_error() != JSON_ERROR_NONE) {
    var_dump($response);
    throw new Exception('Invalid JSON: ' . json_last_error_msg());
  }
  // write cache
  file_put_contents($cachefile, json_encode($json));

  $toremove = ['licence', 'boundingbox', 'osm_id', 'place_id'];
  foreach($toremove as $t) {
    if (isset($json[$t])) {
      unset($json[$t]);
    }
  }
  return $json;
} // getCity

// Call the function and print the results
try {
  $response = getPOIsInRectangle($center, $southWest, $northEast);
  // var_dump($response);
  // clean it up a little for better DEV readability
  cleaner($response);
  // var_dump($response);
  // echo "\n\n===\n\n";
  $nominatim = getNominatim($center);
  // var_dump($cityInfo);
  // echo "\n\n===\n\n";
} catch (Exception $e) {
  echo 'Caught exception: ',  $e->getMessage(), "\n";
  exit();
}

$display_name = '';
$neighborhood = '';
$road = '';
$city_district = '';
$city = '';
$municipality = '';
$county = '';
$postcode = '';
if (isset($nominatim['display_name'])) {
  $display_name = $nominatim['display_name'];
}
if (isset($nominatim['address'])) {
  if (isset($nominatim['address']['neighbourhood'])) {
    $neighborhood = $nominatim['address']['neighbourhood'];
  }
  if (isset($nominatim['address']['road'])) {
    $road = $nominatim['address']['road'];
  }
  if (isset($nominatim['address']['city_district'])) {
    $city_district = $nominatim['address']['city_district'];
  }
  if (isset($nominatim['address']['city'])) {
    $city = $nominatim['address']['city'];
  }
  if (isset($nominatim['address']['municipality'])) {
    $municipality = $nominatim['address']['municipality'];
  }
  if (isset($nominatim['address']['county'])) {
    $county = $nominatim['address']['county'];
  }
  if (isset($nominatim['address']['postcode'])) {
    $postcode = $nominatim['address']['postcode'];
  }
}

// overwrite city - this solves problems with different parts of larger cities having their own names
if (isset($_GET['city'])) {
  $city = urldecode($_GET['city']);
}


$admin_levels = getAdminLevels($response);
krsort($admin_levels);
//remove duplicates
// echo "Admin levels:\n";
// var_dump($admin_levels);
// echo "\n\n===\n\n";
// build a human-readable address

$area_str_en = '';
$area_str_local = '';
foreach($admin_levels as $lvl) {
  if(isset($lvl['name:en'])) {
    $area_str_en .= ', ' . $lvl['name:en'];
  }
  if(isset($lvl['name'])) {
    $area_str_local .= ', ' . $lvl['name'];
  }
}
$area_str_en = ltrim($area_str_en, ', ');
$area_str_en = implode(', ', array_unique(explode(', ', $area_str_en)));
$area_str_local = ltrim($area_str_local, ', ');
$area_str_local = implode(', ', array_unique(explode(', ', $area_str_local)));
$address = $area_str_en;
// append local version, if it exists
if ($area_str_en !== $area_str_local) {
  $address = $address . ' (' . $area_str_local . ')';
}
// echo "address:\n" . $address;
// echo "\n\n===\n\n";


$lowest_admin = reset($admin_levels);
if (isset($lowest_admin['name:en'])) {
  $lowest_admin = $lowest_admin['name:en'];
} elseif (isset($lowest_admin['int_name'])) {
  $lowest_admin = $lowest_admin['int_name'];
} else {
  $lowest_admin = $lowest_admin['name'];
}


$country = end($admin_levels)['name:en'];
if (str_contains(strtolower($country), 'united kingdom')) {
  $country = 'UK';
}
if (str_contains($country, '(')) {
  $country = preg_replace('~\s?\(.*\)~i', '', $country);
}
// echo "country:" . strtolower($country);
// echo "\n\n===\n\n";


$pois = filterPOIs($response);
// var_dump($pois);
// echo "\n\n===\n\n";

$poi_str = formatPOIs($response, $center);
// echo $poi_str;
// echo "\n\n===\n\n";


// if ($address) {
//   $DMScoords = $DMScoords . " (" . $center[0].", ".$center[1] . ")" . ",\n";
//   $address = $address . ".";
// } else {
  $DMScoords = $DMScoords . " (" . $center[0].", ".$center[1] . ")";
// }


$country_filename = str_replace('federal republic of ', '', strtolower($country));


$crimeincountry = file_get_contents('./data/wikipedia/crime-in-country/crime-in-'.$country_filename.'.md');
$crimeincountry = token_saving($crimeincountry);

$cityadvisory = file_get_contents('./data/numbeo/'.strtolower($city).'.txt');;
$cityadvisory = token_saving($cityadvisory);


$prompt = file_get_contents('./prompt-user.tpl');
$prompt = str_replace("{{gender}}", $gender, $prompt);
$prompt = str_replace("{{age}}", strval($age), $prompt);
$prompt = str_replace("{{travelmode}}", $travelmode, $prompt);
$prompt = str_replace("{{datetime}}", $datetime, $prompt);
$prompt = str_replace("{{geocoords}}", $DMScoords, $prompt);
$prompt = str_replace("{{address}}", $address, $prompt);
$prompt = str_replace("{{country}}", $country, $prompt);
$prompt = str_replace("{{area_str_en}}", $area_str_en, $prompt);
$prompt = str_replace("{{radius}}", strval($radius), $prompt);
$prompt = str_replace("{{pois}}", $poi_str, $prompt);
$prompt = str_replace("{{crimeincountry}}", $crimeincountry, $prompt);
$prompt = str_replace("{{lowest_admin}}", $lowest_admin, $prompt);
$prompt = str_replace("{{display_name}}", $display_name, $prompt);
$prompt = str_replace("{{neighborhood}}", $neighborhood, $prompt);
$prompt = str_replace("{{road}}", $road, $prompt);
$prompt = str_replace("{{city_district}}", $city_district, $prompt);
$prompt = str_replace("{{city}}", $city, $prompt);
$prompt = str_replace("{{municipality}}", $municipality, $prompt);
$prompt = str_replace("{{county}}", $county, $prompt);
$prompt = str_replace("{{postcode}}", $postcode, $prompt);
$prompt = str_replace("{{cityadvisory}}", $cityadvisory, $prompt);


try {
  $advisory = file_get_contents('./data/advisory/'.$country_filename.'.md');
} catch (Exception $e) {
  $advisory = '-';
}

if (strtolower($country) === 'germany') {
  // for Germany, add general LGBTIQ advisory
  $advisory .= "\n
LGBTIQ
---

Es gibt keine Hinweise auf besondere Schwierigkeiten, die Akzeptanz ist insbesondere in Großstädten gut ausgeprägt.

-    Beachten Sie die allgemeinen Hinweise für LGBTIQ.
";
  // for Germany, add the worldwide terrorism warning, just as in all other countries
  // $advisory = "Sicherheit\n===\n\nTerrorismus\n---\n\n-    Beachten Sie den weltweiten Sicherheitshinweis." . $advisory;
}


// replace general LGBTIQ advisory, if needed
if (str_contains($advisory, 'Beachten Sie die allgemeinen Hinweise für LGBTIQ')) {
  $LGBTIQ = file_get_contents('./data/advisory/LGBTIQ.md');
  $LGBTIQ = rtrim($LGBTIQ, "\n");
  $advisory = str_replace("-    Beachten Sie die allgemeinen Hinweise für LGBTIQ.", $LGBTIQ, $advisory);
}

// replace worldwide security warning, if needed
// if (str_contains($advisory, 'Beachten Sie den weltweiten Sicherheitshinweis')) {
//   $worldwide = file_get_contents('./data/advisory/worldwide.md');
//   $worldwide = rtrim($worldwide, "\n");
//   $advisory = str_replace("-    Beachten Sie den weltweiten Sicherheitshinweis.", $worldwide, $advisory);
// }

$advisory = token_saving($advisory);

$prompt = str_replace("{{advisory}}", $advisory, $prompt);


$system = file_get_contents('./prompt-system.tpl');
$system = str_replace("{{travelmode}}", $travelmode, $system);
$system = str_replace("{{country}}", $country, $system);
$system = str_replace("{{city}}", $city, $system);
$system = str_replace("{{advisory}}", $advisory, $system);
$system = str_replace("{{crimeincountry}}", $crimeincountry, $system);
$system = str_replace("{{cityadvisory}}", $cityadvisory, $system);


// write prompt
file_put_contents('./cache/prompts/' . $cachestring . '.md', $system.$prompt);

// DEV
// echo "PROMPT";
// echo "\n===\n";
// echo $system;
// echo $prompt;
// echo "\n\n===\n\n";

// $tokens = explode(' ', $tokentest);
// $tokens = count($tokens) * 10/7.5;
// $approx_tokens = strlen($system.$prompt) / 4;
// echo "Approx. tokens: " . $approx_tokens . "\n\n";


// call the OpenAI API
use GuzzleHttp\Client;

function getChatCompletion($system, $prompt) {
  $cacheid = hash('ripemd160', $system.$prompt);
  $cachefile = './cache/ratings/'.$cacheid.'.json';
  // cache check
  if (file_exists($cachefile)) {
    // Read the JSON file and decode the JSON file
    $cached = json_decode(file_get_contents($cachefile), true);
    // indicate that this query was cached
    $cached['cached'] = true;
    return $cached;
  }

  // *******************************
  $model = 'gpt-3.5-turbo-16k'; // 'gpt-4-0613'; // 32k context: "gpt-4-32k" // not available as of July 2023;
  // $model = 'gpt-4o-2024-08-06';
  // $model = 'gpt-4-turbo-2024-04-09';
  // *******************************
  
  $client = new Client(['base_uri' => 'https://api.openai.com']);

  $openaiApiKey = getenv('OPENAI_API_KEY');

  $options = [
        'headers' => [
            'Authorization' => 'Bearer '.$openaiApiKey,
            'Content-Type' => 'application/json'
        ],
        'json' => [
            "model" => $model, // gpt-3.5-turbo
            "messages" => [
              ["role" => "system", "content" => $system],
              ["role" => "user", "content" => $prompt]
            ], 
            'max_tokens' => 1, // The maximum number of tokens to generate in the chat completion. The total length of input tokens and generated tokens is limited by the model's context length. Example Python code for counting tokens.
            // 'top_p' => 1,
            'n' => 1, // How many chat completion choices to generate for each input message.
            'temperature' => 1, // What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic. We generally recommend altering this or top_p but not both.
            'presence_penalty' => 0, // Number between -2.0 and 2.0. Positive values penalize new tokens based on whether they appear in the text so far, increasing the model's likelihood to talk about new topics.
            'frequency_penalty' => 0, // Number between -2.0 and 2.0. Positive values penalize new tokens based on their existing frequency in the text so far, decreasing the model's likelihood to repeat the same line verbatim.
            // 'user' => 'tourist'.$gender.'-'.$age..'-'.$travelmode,
        ]
    ];

  try {
    $response = $client->request('POST', '/v1/chat/completions', $options);
  } catch (\GuzzleHttp\Exception\RequestException $ex) {
       var_dump($ex->getResponse()->getBody()->getContents());
       die;
  }

  $body = $response->getBody();
  $arr_body = json_decode($body);
  // var_dump($arr_body);

  $usage = $arr_body->usage;

  if ($options['json']['n'] > 1) {
    $rating = [
      'ratings' => [],
    ];
    foreach ($arr_body->choices as $r) {
      $rating['ratings'][] = $r->message->content;
    }
    $rating['stdev'] = stats_standard_deviation($rating['ratings']);
    $rating['variance'] = stats_variance($rating['ratings']);
    $rating['mean'] = array_sum($rating['ratings'])  / count($rating['ratings']);
    $values = array_count_values($rating['ratings']); 
    $rating['mode'] = array_search(max($values), $values);
    $rating['min'] = min($rating['ratings']);
    $rating['max'] = max($rating['ratings']);
    $rating['spread'] = max($rating['ratings']) - min($rating['ratings']);
  } else {
    $rating = $arr_body->choices[0]->message->content;
  }

  $output = [
    'usage' => $usage,
    'rating' => $rating,
  ];

  // write cache
  file_put_contents($cachefile, json_encode($output));

  // indicate that this query was not cached
  $output['cached'] = false;

  return $output;
}

$res = getChatCompletion($system, $prompt);

header('Content-type: application/json');

// var_dump($res);

echo json_encode($res);

?>
