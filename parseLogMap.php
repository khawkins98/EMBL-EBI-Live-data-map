<?php
  if (php_sapi_name() == 'cli') {
    echo "Script cannot run in CLI\n";
    exit;
  }

  // A proxy for the EMBL-EBI Live Data Map
  // fetch KML and return as simple CSV

  // the available sources
  $dataSources = array(
    "ebi" => "http://ves-ebi-0f.ebi.ac.uk/ebiwebtrafficmap/fcgi-bin/ebi.fcgi",
    "portals" => "http://ves-ebi-0f.ebi.ac.uk/ebiwebtrafficmap/fcgi-bin/portals.fcgi",
    "uniprot" => "http://ves-ebi-0f.ebi.ac.uk/ebiwebtrafficmap/fcgi-bin/uniprot.fcgi",
  );

  // No automatic caching ...
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Cache-Control: post-check=0, pre-check=0", false);
  header("Pragma: no-cache");

  $isMonitor = strpos($_SERVER['HTTP_USER_AGENT'], 'StatusCake') !== FALSE; 
  $isEbi = strpos(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST), 'ebi.ac.uk') !== FALSE; 

  // Don't allow direct use of the file except StatusCake, and don't allow non-EBI hosted use of file
  if (!$isMonitor && !$isEbi) {
    http_response_code(403);
    exit;
  }

  // Which file(s) is the requester after?
  $desiredFile = isset($_GET['file']) ? htmlspecialchars($_GET["file"]) : '';

  // what's been requested
  switch ($desiredFile) {
    case 'all':
    default:
      $desiredFile = 'all'; // cleanup wild url requests
      $desiredFiles = array($dataSources["ebi"],$dataSources["portals"],$dataSources["uniprot"]);
      $pointType = array('markerClustersEBI','markerClustersPortals','markerClustersUniprot');
      break;
    case 'ebi':
      $desiredFiles = array($dataSources[$desiredFile]);
      $pointType = array('markerClustersEBI');
      break;
    case 'portals':
      $desiredFiles = array($dataSources[$desiredFile]);
      $pointType = array('markerClustersPortals');
      break;
    case 'uniprot':
      $desiredFiles = array($dataSources[$desiredFile]);
      $pointType = array('markerClustersUniprot');
      break;
  }

  // set up a local file based cache
  $cacheFileName = 'cache/' . $desiredFile . '.csv';
  // is the data already cahced?
  if (file_exists($cacheFileName)) {
    // use cache if less than XXX seconds old
    if (time() - filemtime($cacheFileName) < 6) {
      header("Map-cache-creation: ". urlencode(filemtime($cacheFileName)));
      header("Map-cache-server-time: ". urlencode(time()));
      require $cacheFileName;
      exit; // dump the cahced contents and go home
    }
  }

  // OK then, we're getting new data...

  // Function to fetch the file
  // We use curl as we can't use nicer functions on our infrastructure
  function curlFileAsXml($url) {
    $curl_handle=curl_init();
    curl_setopt($curl_handle,CURLOPT_URL,$url);
    curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2);
    curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1);

    $buffer = curl_exec($curl_handle);
    $return_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);

    curl_close($curl_handle);

    if (empty($buffer) || $return_code !== 200) {
      echo "Data source returning error code: $return_code\n";
      http_response_code(204);
      exit;
    } else {
      return simplexml_load_string($buffer);
    }
  }

  // Reusable function to fuzzy-ify the lat/long string
  // in: 0.7000,51.5333,0
  // out: 0.70,51.53,0
  function lowerPrecision($incoming) {
    $processed = explode(",", $incoming);

    $processed[0] = round($processed[0], 2);
    $processed[1] = round($processed[1], 2);

    $output = $processed[0] . "," . $processed[1] . "," . $processed[2];

    return $output;
  }

  // Write the data CSV
  // -----------
  // CSV header
  $output = "target-layer,longitude,latitude,point-type \r\n";
  // to do: in the future, we could add a column to indicate the number of times a lat/lon combination appears so we can add N number of dots instead of one at a time. Would offer over the wire savings, and perhaps speed up the JS

  foreach ($desiredFiles as $key => $desiredFile) {
    // Get the data
    $fetchedData = curlFileAsXml($desiredFile);

    // Results
    $rowCounter = 0;
    foreach($fetchedData as $item) {
      $rowCounter++;
      $output .= $pointType[$key] . "," . lowerPrecision($item->Point->coordinates) . "\r\n";
    }
  }

  // write the new contents
  echo $output;

  // save to the cache
  file_put_contents($cacheFileName, $output);
