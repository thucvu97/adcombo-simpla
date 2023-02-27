<?php
// Importing libraries
require_once dirname(__FILE__) . '/vendor/autoload.php';
// Importing config
require_once dirname(__FILE__) . '/config.php';

use GeoIp2\Database\Reader;

const LEAD = 'lead';
const ORDER = 'order';

// Get list of IP addresses that are recorded in user's HTTP headers
function Get_Ip_List($headers)
{
    $ip_list = array();
    // This is header we are looking into
    $ip_headers = array(
        'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED_FOR',
        'HTTP_X_COMING_FROM', 'HTTP_COMING_FROM', 'HTTP_FORWARDED_FOR_IP',
        'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'
    );
    // Order of headers is important, do not change!
    foreach ($ip_headers as $header) {
        if (array_key_exists($header, $headers)) {
            foreach (explode(',', $headers[$header]) as $ip) {
                if (
                    filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) &&
                    filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)
                ) {
                    array_push($ip_list, $ip);
                }
            }
        }
    }
    return $ip_list;
}

/**
 * @param $ip_list array generated by Get_Ip_List
 * @return array with ip, country name and country iso code
 */
function Get_GeoIP($ip_list)
{
    try {
        $reader = new Reader(dirname(__FILE__) . '/GeoLite2-Country.mmdb');
        if (isset($reader)) {
            foreach ($ip_list as $ip) {
                try {
                    $record = $reader->country($ip);
                    $geoip = array(
                        'ip' => $ip,
                        'name' => $record->country->name,
                        'isoCode' => $record->country->isoCode
                    );
                    return $geoip;
                } catch (GeoIp2\Exception\AddressNotFoundException $e) {
                    continue;
                }
            }
        }
    } catch (MaxMind\Db\Reader\InvalidDatabaseException $e) {}
    // We will set to this value if DB not found
    return array(
        'ip' => $_SERVER['REMOTE_ADDR'],
        'name' => "Other Country",
        'isoCode' => "O1"
    );
}

// Log order result to orderlog.php
function Log_Request($model, $params, $request_url, $response)
{
    $log_filename = 'orderlog.php';
    if ($model === LEAD) {
        $log_filename = 'leadlog.php';
    }
    $date_now = date('Y-m-d H:i:s');
    if (file_exists($log_filename)) {
        $fp = fopen($log_filename, 'a');
    } else {
        $fp = fopen($log_filename, 'a');
        fwrite($fp, "<?php exit(\"Access Denied\"); ?>\n");
    }
    fwrite($fp, "Offer id: {$params['offer_id']}\nIP: {$params['ip']}\nReferer: {$params['referrer']}\nDate: {$date_now}\nRequest URL: {$request_url}\nResponse: {$response}\n\n\n=====================\n\n\n");
    fclose($fp);
}

function Add_Custom_Params($params)
{
    if (defined('ACLandingConfig::CUSTOM_PARAMS')) {
        $custom_params = array();
        foreach (ACLandingConfig::CUSTOM_PARAMS as $param => $value) {
            if (preg_match('/{\w+}|^$/', $value) === 0) {
                $custom_params[$param] = $value;
            }
        }
    $params = array_merge($params, $custom_params);
    }
    return $params;
}

// Creating or updating order
function Send_Order($request, $action)
{
    unset($request['PHPSESSID']);

    $request['api_key'] = ACLandingConfig::API_KEY;

    if ($action == 'create') {
        $method = "GET";
        $request['ip'] = isset($request['ip']) ? $request['ip'] : $_SERVER['REMOTE_ADDR'];
        $request['base_url'] = $_SERVER['HTTP_REFERER'];
        // Failover. If JavaScript footer failed, add custom params to request, filtering out macros and empty strings.
        $request = Add_Custom_Params($request);

        $landing = parse_url($_SERVER['HTTP_REFERER']);
        if (isset($landing['query'])) {parse_str($landing['query'], $land_params);}else{$land_params = array();}
        $request_url = ACLandingConfig::API_URL . '?' . http_build_query($request + $land_params, '', '&');
    } elseif ($action == 'update') {
        $method = "PUT";
        $request_url = str_replace('/create/', '/update/', ACLandingConfig::API_URL) . '?' . http_build_query($request, '', '&');
    }


    $context = stream_context_create(array(
        "http" => array(
            "ignore_errors" => true,
            'timeout' => 10,
            "method" => $method,
            "header" => $_SERVER
        ),
        "ssl" => array(
            "verify_peer" => false,
            "verify_peer_name" => false,
        ),
    ));

    // Finally, make request to API. file_get_contents needs custom error handler otherwise set ignore_errors
    set_error_handler(
        function ($severity, $message, $file, $line) {
            throw new ErrorException($message, $severity, $severity, $file, $line);
        }
    );
    try {
        $resp = file_get_contents($request_url, false, $context);
    } catch (Exception $e) {
        $resp = json_encode(array(
            "code" => "exception",
            "error" => $e->getMessage(),
        ));
    }
    restore_error_handler();

    // Log order
    if (ACLandingConfig::LOG_ENABLED) {
        Log_Request(ORDER, $request, $request_url, $resp);
    }
    $data = json_decode($resp, true);
    return $data;
}

// Template "rendering". Currently only corrects paths and adds JS code to the footer
function Render_Template($content_path, $is_mobile)
{
    $rendered_template = file_get_contents(dirname(__FILE__) . '/' . $content_path . 'index.html');
    $rendered_template = preg_replace(
        '/(href|src|srcset)=(\")?(\.\.\/)?((\w+)\/[\w+\/\.\-]+)([\"\s>])/i',
        '${1}=${2}' . $content_path . '${4}${6}',
        $rendered_template
    );

    // If GEOIP_ENABLED = true in config file and we have GeoIP Database, then detect visitors geo
    if (ACLandingConfig::GEOIP_ENABLED && file_exists(dirname(__FILE__) . '/GeoLite2-Country.mmdb')) {
        $geo_ip = Get_GeoIP(Get_Ip_List($_SERVER));
        $ip = $geo_ip['ip'];
        $ip_country = $geo_ip['isoCode'];
        $ip_country_name = $geo_ip['name'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
        $ip_country = '';
        $ip_country_name = '';
    }
    $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "";

    // Add custom parameters
    $custom_params = '';
    if (defined('ACLandingConfig::CUSTOM_PARAMS')) {
        foreach (ACLandingConfig::CUSTOM_PARAMS as $param => $value) {
            if ($value !== '') {
                $custom_params = $custom_params . 'form.appendChild(inputElem("' . $param . '", "' . $value . '"));';
            }
        }
    }
    // This JavaScript will add all needed data to order form
    /** @noinspection CommaExpressionJS ExpressionStatementJS JSUnnecessarySemicolon */
    $jsfooter = <<<EOV
<script type='text/javascript'>
function inputElem(name,value) {
    let element = document.createElement("input");
    element.setAttribute("type", "hidden");
    element.setAttribute("name", name);
    element.setAttribute("value", value);
    return element;
}
document.querySelectorAll('form').forEach(
    form => {
        form.appendChild(inputElem("referrer", "$referrer"));
        form.appendChild(inputElem("ip", "$ip"));
        form.appendChild(inputElem("ip_country", "$ip_country"));
        form.appendChild(inputElem("ip_country_name", "$ip_country_name"));
        // Here be custom parameters
        $custom_params
    }
);
</script>
EOV;

    $rendered_template = preg_replace(
        '/\<\/body\>/i',
        $jsfooter,
        $rendered_template
    );
    eval('?>' . $rendered_template);
}

// Make request to payment/proxy or payment/second api and return payment response.
function Get_Payment_Resp($request, $updated_esub, $goods_id)
{
    $request_url = ACLandingConfig::API_URL_PAYMENT_REDIRECT;

    $request['esub'] = $updated_esub;
    $request['g_id'] = $goods_id;
    $options = array(
        'http' => array(
            'ignore_errors' => true,
            'timeout' => 30,
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($request),
        ),
    );
    $context = stream_context_create($options);

    set_error_handler(
        function ($severity, $message, $file, $line) {
            throw new ErrorException($message, $severity, $severity, $file, $line);
        }
    );

    try {
        $resp = file_get_contents($request_url, false, $context);
    } catch (Exception $e) {
        $resp = json_encode(array(
            'error' => 'Error while getting payment url'
        ));
    }
    restore_error_handler();

    return json_decode($resp, true);
}

// Make request to broker api and return resp
function Send_Broker_Lead($request)
{
    $request_url = ACLandingConfig::API_BROKER_URL.'?api_key='.ACLandingConfig::API_KEY;

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    $request = Add_Custom_Params($request);

    $data = [
         'base_url' => $_SERVER['REQUEST_URI'], // or 'base_url' => 'http://my-domain.com/',
         'referrer' => $_SERVER['HTTP_REFERER'], // or 'referrer' => 'http://my-click-site.com',
         'first_name' => isset($request['first_name']) ? $request['first_name'] : '',
         'last_name' => isset($request['last_name']) ? $request['last_name'] : '',
         'email' => isset($request['email']) ? $request['email'] : '',
         'phone' => isset($request['phone']) ? $request['phone'] : '',
         'password' => isset($request['password']) ? $request['password'] : '',
         'country_code' => isset($request['country_code']) ? $request['country_code'] : '',
         'esub' => $request['esub'],
         'ip' => $ip,
         'subacc' => $request['subacc'],
         'subacc2' => $request['subacc2'],
         'subacc3' => $request['subacc3'],
         'subacc4' => $request['subacc4'],
         'utm_source' =>$request['utm_source'],
         'utm_medium' => $request['utm_medium'],
         'utm_term' => $request['utm_term'],
         'utm_content' => $request['utm_content'],
         'utm_campaign' => $request['utm_campaign'],
         'test' => ACLandingConfig::TEST_LEAD,
        ];

    $options = array(
        'http' => array(
            'ignore_errors' => true,
            'timeout' => 30,
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
        ),
    );
    $context = stream_context_create($options);

    set_error_handler(
        function ($severity, $message, $file, $line) {
            throw new ErrorException($message, $severity, $severity, $file, $line);
        }
    );

    try {
        $resp = file_get_contents($request_url, false, $context);
        $resp = json_decode($resp, true);
        if (isset($resp['detail'])) {
            $error_msg = null;
            if (isset($resp['detail'][0]['loc'])) {
                $error_msg = "\n";
                foreach($resp['detail'] as $err) {
                    if (array_search('email', $err['loc']) &&
                    preg_match('/string does not match regex/', $err['msg']) === 1) {
                        $error_msg = $error_msg.'email: invalid email'."\n";
                    }
                    else {
                        $error_msg = $error_msg.$err['loc'][1].': '.$err['msg']."\n";
                    }
                }
            } elseif (array_key_exists('detail', $resp) && !isset($resp['detail'][0]['loc'])) {
                $error_msg = $resp['detail'];
            }
            $resp = array(
                'accepted' => false,
                'error' => $error_msg,
                'redirect_url' => null
            );
        }
    } catch (Exception $e) {
        $resp = array(
            'accepted' => false,
            'error' => $e->getMessage(),
            'redirect_url' => null
        );
    }
    restore_error_handler();

    // Log lead
    if (ACLandingConfig::LOG_ENABLED) {
        Log_Request(LEAD, $request, $request_url, json_encode($resp));
    }

    return $resp;
}