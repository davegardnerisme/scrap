#!/usr/bin/php
<?php
/**
 * Analyse jMeter log files and produce summary
 *
 * Usage: jmeterLogAnalyse.php <file> <file> <file>
 *
 * @author Dave Gardner (dave@davegardner.me.uk)
 *
 */

define('FILESIZE_LIMIT_BYTES', 10485760);    // 10 MB
define('TMP_LOCATION', '/tmp/');

$header = "\n\033[44;37;01mjMeter log file analysis\033[0m\n\n";

// ---

// 1. parse inputs - test all files exist, make sure we have one file

$argCount = (!empty($_SERVER['argc']) ? $_SERVER['argc'] : 0);
$args = (!empty($_SERVER['argv']) ? $_SERVER['argv'] : array());
array_shift($args);

// show help if no params
if ($argCount == 0 || empty($args) || $args[0] == '--help' || $args[0] == '-h')
{
    echo $header;
    echo "Usage:\n";
    echo " analyse.php <file1> <file2> ...\n";
    echo " analyse.php <directory1> <directory2> ...\n";
    echo " analyse.php --help\n";
    echo "\n";
    exit;
}


// ---

// 2. check size of log files against defined limit, split larger ones into smaller chunks - store in tmp

$analysisFiles = array();
foreach ($args as $filename)
{
    if (is_dir($filename))
    {
        // strip trailing slash
        if (substr($filename, -1) == '/')
        {
            $filename = substr($filename, 0, strlen($filename) -1);
        }

        // read all files in directory - straight from the examples!
        if ($handle = opendir($filename))
        {
            while (false !== ($file = readdir($handle)))
            {
                if ($file != "." && $file != ".." && is_readable($filename.'/'.$file))
                {
                    checkAndSplitLogfile($filename.'/'.$file, $analysisFiles);
                }
            }
            closedir($handle);
        }
    }
    else
    {
        if (!is_readable($filename))
        {
            echo $header;
            "\n\033[37;41mCannot read file \"$filename\"\033[0m\n\n";
            exit(1);
        }
        else
        {
            checkAndSplitLogfile($filename, $analysisFiles);
        }
    }
} // end loop through args

if (empty($analysisFiles))
{
    echo $header;
    echo "\n\033[37;41mNo log files found\033[0m\n\n";
    exit(1);
}


// ---

// 3. loop through xml files and construct base stats

/*

Attribute 	Content
by 	Bytes
de 	Data encoding
dt 	Data type
ec 	Error count (0 or 1, unless multiple samples are aggregated)
hn 	Hostname where the sample was generated
lb 	Label
lt 	Latency = time to initial response (milliseconds) - not all samplers support this
na 	Number of active threads for all thread groups
ng 	Number of active threads in this group
rc 	Response Code (e.g. 200)
rm 	Response Message (e.g. OK)
s 	Success flag (true/false)
sc 	Sample count (1, unless multiple samples are aggregated)
t 	Elapsed time (milliseconds)
tn 	Thread Name
ts 	timeStamp (milliseconds since midnight Jan 1, 1970 UTC) 

 */


/*

format: [<label>] = array with keys:

 'min'      (response time)
 'max'      (response time)
 'sum'      (sum of all response times)
 'latsum'   (sum of all latency times)
 'samples'  (count of number of samples encountered)
 'err'      (sum of all errors)
 'start'    (min sample time - eg: time of first)
 'end'      (max sample time - eg: time of last)
 'bytes'    (total bytes)
 'buckets'  (frequency buckets for response time to nearest 50ms)

 */

$stats = array();

/*
 'responseCodes' [<code>] = frequency
 'assertionFailures' [<msg>] = frequency
 */
$errors = array();

foreach ($analysisFiles as $filename)
{
    $xml = new SimpleXMLElement($filename, NULL, TRUE); // true = data is pathname
    if (!isset($xml->httpSample))
    {
        echo $header;
        echo "\n\033[37;41mFile \"$filename\" contains zero log entries\033[0m\n\n";
        exit(1);
    }

    foreach ($xml->httpSample as $sample)
    {
        $label = (string)$sample->Attributes()->lb;
        $latencyTime = (int)$sample->Attributes()->lt;
        $responseTime = (int)$sample->Attributes()->t;
        $timestamp = (int)$sample->Attributes()->ts;     // milliseconds
        $bytes = (int)$sample->Attributes()->by;
        $success = (string)$sample->Attributes()->s;
        $assertionFailure = (string)$sample->assertionResult->failure;
        if ($success == 'true' && $assertionFailure != 'true')
        {
            $success = true;
        }
        else
        {
            // log error under response code
            if (!isset($errors['responseCodes'][(string)$sample->Attributes()->rc]))
            {
                $errors['responseCodes'][(string)$sample->Attributes()->rc] = 0;
            }
            $errors['responseCodes'][(string)$sample->Attributes()->rc]++;

            // load under assertion failure
            if ($assertionFailure == 'true')
            {
                if (!isset($errors['assertionFailures'][(string)$sample->assertionResult->failureMessage]))
                {
                    $errors['assertionFailures'][(string)$sample->assertionResult->failureMessage] = 0;
                }
                $errors['assertionFailures'][(string)$sample->assertionResult->failureMessage]++;
            }

            // we have FAILED!
            $success = false;
        }

        // label exists?
        if (!isset($stats[$label]))
        {
            $stats[$label] = array(
                'min'=>     NULL,
                'max'=>     0,
                'sum'=>     0,
                'latsum'=>  0,
                'samples'=> 0,
                'err'=>     0,
                'start'=>   NULL,
                'end'=>     0,
                'bytes'=>   0,
                'buckets'=> array()
            );
        }

        // adjust stats based on this sample
        if ($stats[$label]['min'] === NULL || $responseTime < $stats[$label]['min'])
        {
            $stats[$label]['min'] = $responseTime;
        }
        if ($responseTime > $stats[$label]['max'])
        {
            $stats[$label]['max'] = $responseTime;
        }
        $stats[$label]['sum'] += $responseTime;
        $stats[$label]['latsum'] += $latencyTime;
        $stats[$label]['samples']++;
        if (!$success)
        {
            $stats[$label]['err']++;
        }
        if ($stats[$label]['start'] === NULL || $timestamp < $stats[$label]['start'])
        {
            $stats[$label]['start'] = $timestamp;
        }
        if ($timestamp > $stats[$label]['end'])
        {
            $stats[$label]['end'] = $timestamp;
        }

        $bucketStamp = ceil($responseTime/50)*50;

        if (!isset($stats[$label]['buckets'][$bucketStamp]))
        {
            $stats[$label]['buckets'][$bucketStamp] = 1;
        }
        else
        {
            $stats[$label]['buckets'][$bucketStamp]++;
        }
        $stats[$label]['bytes'] += $bytes;

    } // end loop samples

} // end loop files

// ---

// add in totals row

$stats['TOTAL'] = array(
                'min'=>     NULL,
                'max'=>     0,
                'sum'=>     0,
                'latsum'=>  0,
                'samples'=> 0,
                'err'=>     0,
                'start'=>   NULL,
                'end'=>     0,
                'bytes'=>   0,
                'buckets'=> array()
    );
foreach ($stats as $label=>$data)
{
    if ($label != 'TOTAL')
    {
        // adjust stats based on this sample
        if ($stats['TOTAL']['min'] === NULL || $data['min'] < $stats['TOTAL']['min'])
        {
            $stats['TOTAL']['min'] = $data['min'];
        }
        if ($data['max'] > $stats['TOTAL']['max'])
        {
            $stats['TOTAL']['max'] = $data['max'];
        }
        $stats['TOTAL']['sum'] += $data['sum'];
        $stats['TOTAL']['latsum'] += $data['latsum'];
        $stats['TOTAL']['samples'] += $data['samples'];
        $stats['TOTAL']['err'] += $data['err'];
        if ($stats['TOTAL']['start'] === NULL || $data['start'] < $stats['TOTAL']['start'])
        {
            $stats['TOTAL']['start'] = $data['start'];
        }
        if ($data['end'] > $stats['TOTAL']['end'])
        {
            $stats['TOTAL']['end'] = $data['end'];
        }
        $stats[$label]['bytes'] += $data['bytes'];

        foreach ($data['buckets'] as $bucketStamp=>$total)
        {
            if (!isset($stats['TOTAL']['buckets'][$bucketStamp]))
            {
                $stats['TOTAL']['buckets'][$bucketStamp] = 0;
            }
            $stats['TOTAL']['buckets'][$bucketStamp] += $total;
        }
    }
}


// ---

// 4. construct %s and averages etc..

ksort($stats);

foreach ($stats as $label=>$data)
{
    // add in label for ease of output
    $data['label'] = $label;

    // total time samples collected over
    $data['secondsrun'] = ($data['end']-$data['start'])/1000;

    // mean, median, mode: -- for RESPONSE TIMES

    $data['mean'] = $data['sum']/$data['samples'];

    ksort($data['buckets']);

    $halfNumberOfSamples = $data['samples']/2;
    $totalCount = 0;
    $maxBucketCount = 0;
    $biModal = FALSE;
    foreach ($data['buckets'] as $stamp=>$bucketCount)
    {
        $totalCount += $bucketCount;
        if ($totalCount >= $halfNumberOfSamples && !isset($data['median']))
        {
            $data['median'] = $stamp;
        }
        if ($bucketCount > $maxBucketCount)
        {
            $maxBucketCount = $bucketCount;
            $data['mode'] = $stamp;
        }
        elseif ($bucketCount == $maxBucketCount)    // bi-modal (or more)
        {
            $biModal = TRUE;
        }
    }
    if ($biModal)
    {
        $data['mode'] = NULL;
    }

    // std. dev
    $stdDevSum = 0;
    foreach ($data['buckets'] as $stamp=>$bucketCount)
    {
        $diff = $stamp-$data['mean'];
        $stdDevSum += $bucketCount*$diff*$diff;
    }
    $data['stddev'] = sqrt($stdDevSum/$data['samples']);

    // err pc
    $data['errpc'] = ($data['err']/$data['samples'])*100;

    // samples per sec
    $data['samplespersec'] = $data['samples']/$data['secondsrun'];

    // bytes per sec
    $data['bytespersec'] = $data['bytes']/$data['secondsrun'];

    // mean latency
    $data['latmean'] = $data['latsum']/$data['samples'];

    // ---

    $stats[$label] = $data;
}


// ---

// 5. report

/*
            [min] => 17
            [max] => 1793
            [sum] => 40555
            [samples] => 366
            [err] => 0
            [start] => 1276072808414
            [end] => 1276076442282
            [bytes] => 8146884
            [buckets] => Array
                (
                    [50] => 206
                    [100] => 114
                    [150] => 8
                    [300] => 6
                    [350] => 7
                    [400] => 6
                    [450] => 2
                    [500] => 1
                    [550] => 1
                    [800] => 1
                    [850] => 2
                    [900] => 2
                    [1000] => 2
                    [1050] => 3
                    [1200] => 1
                    [1350] => 1
                    [1400] => 1
                    [1800] => 2
                )

            [secondsrun] => 3633.868
            [mean] => 110.806010929
            [median] => 50
            [mode] => 50
            [stddev] => 231.475161912
            [errpc] => 0
            [samplespersec] => 0.100719123534
            [bytespersec] => 2241.93173775
*/

$cols = array(
    'label'=>           'Label               ',
    'samplespersec'=>   'Smp/sec     ',
    'min'=>             'Min         ',
    'max'=>             'Max         ',
    'mean'=>            'Mean        ',
    'median'=>          'Med         ',
    'mode'=>            'Mode        ',
    'stddev'=>          'StDev       ',
    'errpc'=>           'Err %       ',
    'latmean'=>         'Latency     ',
    'kbpersec'=>        'Bandw (kb/s)'
    );

echo $header;
echo "Start:      \033[01m".date('H:i:s j/n/Y', $stats['TOTAL']['start']/1000)."\033[0m\n";
echo "End:        \033[01m".date('H:i:s j/n/Y', $stats['TOTAL']['end']/1000)."\033[0m\n";
echo "Duration:   \033[01m".(int)(($stats['TOTAL']['end']-$stats['TOTAL']['start'])/60000)." mins\033[0m\n";
echo "Log files:  \033[01m".implode(', ', $analysisFiles)."\033[0m\n";
echo "\n";
echo "                                \033[01mRESPONSE TIMES (ms)\033[0m\n";
echo "\033[01m" . implode('', $cols)."\033[0m\n";

$report = array();
$totalCount = count($stats);
$counter = 0;
foreach ($stats as $label=>$data)
{
    $data['samplespersec'] = sprintf('%.2f', $data['samplespersec']);
    $data['mean'] = sprintf('%.1f', $data['mean']);
    $data['stddev'] = sprintf('%.1f', $data['stddev']);
    $data['errpc'] = sprintf('%.1f', $data['errpc']);
    $data['latmean'] = sprintf('%.1f', $data['latmean']);
    $data['kbpersec'] = sprintf('%.2f', $data['latmean']/1024);

    echo "\n";

    if ($counter == $totalCount-1)
    {
        echo "\n";
    }

    if (strlen($label) > 18)
    {
        $label = substr($label, 0, 18) . '.';
    }
    echo "\033[01m" . str_pad($label, 20) . "\033[0m";
    foreach ($cols as $colName=>$colLabel)
    {
        if ($colName != 'label')
        {
            echo str_pad($data[$colName], 12);
        }
    }

    $counter++;
}
echo "\n\n";

if (!empty($errors))
{
    echo "\033[37;41mERRORS:\033[0m\n\n";

    if (!empty($errors['responseCodes']))
    {
        echo "\033[01mResponse codes:\033[0m\n";
        arsort($errors['responseCodes']);
        foreach ($errors['responseCodes'] as $code=>$frequency)
        {
            echo str_pad((string)$frequency, 10).$code."\n";
        }
    }

    if (!empty($errors['assertionFailures']))
    {
        echo "\n\033[01mAssertion failures:\033[0m\n";
        arsort($errors['assertionFailures']);
        foreach ($errors['assertionFailures'] as $code=>$frequency)
        {
            echo str_pad((string)$frequency, 10).$code."\n";
        }
    }
    echo "\n\n";
}

// -----------------------------------------------------------------------------

/**
 * Function to check a log file and if required split it into
 *
 * @param string $inputFilename The user supplied log filename
 * @param array $analysisFiles We populate with the names of XML files that will actually be analysed
 */
function checkAndSplitLogfile($inputFilename, &$analysisFiles)
{
    if (filesize($inputFilename) > FILESIZE_LIMIT_BYTES)
    {
        // split it!
        $baseName = md5('SALT'.$inputFilename.time());
        $index = 0;
        $finished = false;

        if (!$fRead = fopen($inputFilename, 'r'))
        {
            echo $header;
            echo "\n\033[37;41mCannot read file \"$filename\"\033[0m\n\n";
            exit(1);
        }

        while (!$finished)
        {
            $index++;

            // open next file for writing
            $tmpFile = TMP_LOCATION.$baseName.'.'.$index.'.xml';
            $fWrite = fopen($tmpFile, 'w');

            // write header
            fwrite($fWrite, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($fWrite, '<testResults version="1.2">'."\n");

            $fileFinished = false;


            $writeFileBytes = 0;
            while (!$fileFinished)
            {
                $line = fgets($fRead, 10000);
                if (!$line)
                {
                    $fileFinished = true;
                    $finished = true;
                }
                else
                {
                    // what is the line?
                    if (strpos($line, '<?xml') !== false
                            || strpos($line, '<testResults') !== false
                            || strpos($line, '</testResults') !== false)
                    {
                        // ignore
                    }
                    else
                    {
                        // we want to write this line
                        fwrite($fWrite, $line."\n");
                        $writeFileBytes += strlen($line);
                    }

                    // written enough to file? AND finished writing block?
                    if ($writeFileBytes > FILESIZE_LIMIT_BYTES && strpos($line, '</httpSample>') !== false)
                    {
                        $fileFinished = true;
                    }

                }
            } // end loop while still writing to current output file

            // finish off current output file
            fwrite($fWrite, '</testResults>'."\n");
            fclose($fWrite);

            // add to list to be processed
            $analysisFiles[] = $tmpFile;

        } // end loop through processing input file

        fclose($fRead);
    }
    else
    {
        $analysisFiles[] = $inputFilename;
    }
}

