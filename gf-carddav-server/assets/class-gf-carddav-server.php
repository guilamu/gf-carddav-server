<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Server {
    /**
     * @var GF_CardDAV_Settings
     */
    private $settings;

    /**
     * @var GF_CardDAV_Auth
     */
    private $auth;

    /**
     * @var GF_CardDAV_Directory
     */
    private $directory;

    /**
     * @var GF_CardDAV_VCF
     */
    private $vcf;

    /**
     * @var GF_CardDAV_Principal
     */
    private $principal;

    /**
     * @var GF_CardDAV_Logger
     */
    private $logger;

    /**
     * @var array|null
     */
    private $request_context = null;

    public function __construct($settings, $auth, $directory, $vcf, $principal, $logger) {
        $this->settings  = $settings;
        $this->auth      = $auth;
        $this->directory = $directory;
        $this->vcf       = $vcf;
        $this->principal = $principal;
        $this->logger    = $logger;
    }

    public function maybe_handle_request() {
        $request = $this->get_request_context();
        $route = $request['route'];

        if (! $route) {
            return;
        }

        $start = microtime(true);
        $request_uri = $request['request_uri'];

        if ($route === 'well-known') {
            header('Location: ' . $this->principal->get_service_url(), true, 301);
            exit;
        }

        if ($route === 'root') {
            $route = 'service';
        }

        if (! is_ssl()) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__( 'HTTPS required', 'gf-carddav-server' );
            exit;
        }

        $auth = $this->auth->authenticate();

        if ($auth['status'] === 'missing' || $auth['status'] === 'invalid') {
            $this->auth->send_auth_challenge();
        }

        if ($auth['status'] === 'forbidden') {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__( 'Forbidden', 'gf-carddav-server' );
            exit;
        }

        $user = $auth['user'];

        $this->logger->log('Handling CardDAV request', array(
            'route'  => $route,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
            'user'   => $user->user_login,
        ));

        switch ($route) {
            case 'service':
                $this->handle_service($user);
                break;
            case 'principal':
                $this->handle_principal($user);
                break;
            case 'contacts':
                $this->handle_contacts();
                break;
            case 'contact':
                $this->handle_contact();
                break;
            default:
                status_header(404);
                exit;
        }

        $this->logger->log('CardDAV request complete', array('route' => $route, 'duration_ms' => (int) round((microtime(true) - $start) * 1000)));
        exit;
    }

    public function is_carddav_request() {
        $request = $this->get_request_context();

        return $request['route'] !== '';
    }

    private function get_request_context() {
        if (is_array($this->request_context)) {
            return $this->request_context;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        $route = (string) get_query_var('gf_carddav_route');
        $principal = (string) get_query_var('gf_carddav_principal');
        $entry_id = absint(get_query_var('gf_carddav_entry'));

        if ($route === '') {
            $path = $this->get_normalized_request_path($request_uri);
            $matched = $this->match_route_from_path($path, $method);
            $route = $matched['route'];
            $principal = $matched['principal'];
            $entry_id = $matched['entry_id'];
        }

        $this->request_context = array(
            'route' => $route,
            'principal' => $principal,
            'entry_id' => $entry_id,
            'method' => $method,
            'request_uri' => $request_uri,
        );

        return $this->request_context;
    }

    private function get_normalized_request_path($request_uri) {
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

        if ($home_path !== '' && $home_path !== '/' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }

        $path = '/' . ltrim($path, '/');

        if ($path === '//') {
            $path = '/';
        }

        return $path;
    }

    private function match_route_from_path($path, $method) {
        $result = array(
            'route' => '',
            'principal' => '',
            'entry_id' => 0,
        );

        if ($path === '/.well-known/carddav' || $path === '/.well-known/carddav/') {
            $result['route'] = 'well-known';

            return $result;
        }

        if ($path === '/carddav' || $path === '/carddav/') {
            $result['route'] = 'service';

            return $result;
        }

        if (preg_match('#^/carddav/principals/([^/]+)/?$#', $path, $match)) {
            $result['route'] = 'principal';
            $result['principal'] = rawurldecode($match[1]);

            return $result;
        }

        if ($path === '/carddav/contacts' || $path === '/carddav/contacts/') {
            $result['route'] = 'contacts';

            return $result;
        }

        if (preg_match('#^/carddav/contacts/gf-([0-9]+)\.vcf$#', $path, $match)) {
            $result['route'] = 'contact';
            $result['entry_id'] = (int) $match[1];

            return $result;
        }

        if ($path === '/' && in_array($method, array('PROPFIND', 'OPTIONS'), true)) {
            $result['route'] = 'root';
        }

        return $result;
    }

    private function handle_service($user) {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        if ($method === 'OPTIONS') {
            $this->send_options();
        }

        if ($method !== 'PROPFIND') {
            status_header(405);
            exit;
        }

        $response = $this->principal->build_service_discovery_response($user->user_login);
        $this->send_multistatus(array($response));
    }

    private function handle_principal($user) {
        $request = $this->get_request_context();
        $method = $request['method'];
        $requested_principal = $request['principal'];

        if ($requested_principal !== '' && $requested_principal !== $user->user_login) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__( 'Forbidden', 'gf-carddav-server' );
            exit;
        }

        if ($method === 'OPTIONS') {
            $this->send_options();
        }

        if ($method !== 'PROPFIND') {
            status_header(405);
            exit;
        }

        $response = $this->principal->build_propfind_response($user->user_login);
        $this->send_multistatus(array($response));
    }

    private function handle_contacts() {
        $request = $this->get_request_context();
        $method = $request['method'];

        if (! $this->directory->gravity_forms_available()) {
            status_header(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__( 'Gravity Forms unavailable', 'gf-carddav-server' );
            exit;
        }

        switch ($method) {
            case 'OPTIONS':
                $this->send_options();
                break;
            case 'PROPFIND':
                $this->handle_contacts_propfind();
                break;
            case 'REPORT':
                $this->handle_contacts_report();
                break;
            case 'GET':
                status_header(405);
                exit;
            default:
                status_header(405);
                exit;
        }
    }

    private function handle_contact() {
        $request  = $this->get_request_context();
        $method   = $request['method'];
        $entry_id = (int) $request['entry_id'];
        $entry    = $this->directory->get_entry($entry_id);

        if (! $entry) {
            status_header(404);
            exit;
        }

        if ($method === 'PROPFIND') {
            $resource = $this->build_contact_response($entry, false);
            $this->send_multistatus(array($resource));
        }

        if ($method !== 'GET') {
            status_header(405);
            exit;
        }

        // Build vCard once, derive ETag from it (avoids double build_vcard call).
        $vcard = $this->vcf->build_vcard($entry);
        $etag  = '"' . md5($vcard) . '"';

        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';

        // Strip weak validator prefix — Apache mod_deflate may add W/ automatically.
        if (strpos($if_none_match, 'W/') === 0) {
            $if_none_match = substr($if_none_match, 2);
        }

        if ($if_none_match !== '' && $if_none_match === $etag) {
            status_header(304);
            header('ETag: ' . $etag);
            exit;
        }

        status_header(200);
        header('Content-Type: text/vcard; charset=utf-8');
        header('ETag: ' . $etag);
        header('Content-Length: ' . strlen($vcard));
        echo $vcard;
        exit;
    }

    private function handle_contacts_propfind() {
        $depth     = isset($_SERVER['HTTP_DEPTH']) ? trim((string) $_SERVER['HTTP_DEPTH']) : '0';
        $responses = array($this->build_collection_response());

        if ($depth === '1') {
            $entries = $this->directory->get_active_entries();
            $this->logger->log('PROPFIND depth 1 listing contacts', array('count' => count($entries)));

            foreach ($entries as $entry) {
                $responses[] = $this->build_contact_response($entry, false);
            }
        }

        $this->send_multistatus($responses);
    }

    private function handle_contacts_report() {
        $body = file_get_contents('php://input');

        if (! is_string($body) || $body === '') {
            $body = '';
        }

        $is_multiget = strpos($body, 'addressbook-multiget') !== false;
        $entries     = $is_multiget ? $this->get_report_requested_entries($body) : $this->directory->get_active_entries();
        $responses   = array();

        $this->logger->log('REPORT contacts', array('multiget' => $is_multiget, 'count' => count($entries)));

        foreach ($entries as $entry) {
            $responses[] = $this->build_contact_response($entry, true);
        }

        $this->send_multistatus($responses);
    }

    /**
     * Parse the multiget REPORT XML body and fetch the requested entries.
     *
     * Uses DOMDocument/XPath instead of regex for robust XML namespace handling.
     * Collects all entry IDs and fetches them in a single GFAPI call.
     *
     * @param string $body Raw XML body from the client.
     * @return array
     */
    private function get_report_requested_entries($body) {
        $xml = new DOMDocument();

        $use_errors = libxml_use_internal_errors(true);

        if (! @$xml->loadXML($body)) {
            libxml_use_internal_errors($use_errors);

            return array();
        }

        libxml_use_internal_errors($use_errors);

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('d', 'DAV:');
        $nodes = $xpath->query('//d:href');

        $entry_ids = array();

        foreach ($nodes as $node) {
            $href = $node->nodeValue;

            if (preg_match('#gf-([0-9]+)\.vcf#', $href, $match)) {
                $entry_ids[] = (int) $match[1];
            }
        }

        if (empty($entry_ids)) {
            return array();
        }

        return $this->directory->get_entries_by_ids($entry_ids);
    }

    private function build_collection_response() {
        $url_path = wp_parse_url($this->principal->get_contacts_url(), PHP_URL_PATH);

        return array(
            'href'       => $url_path,
            'properties' => array(
                'd:displayname'         => __( 'GF Contacts', 'gf-carddav-server' ),
                'd:getcontenttype'      => 'httpd/unix-directory',
                'cs:getctag'            => $this->directory->get_collection_ctag(),
                'd:resourcetype'        => array(
                    'd:collection' => null,
                    'card:addressbook' => null,
                ),
                'card:supported-address-data' => array(
                    'card:address-data-type' => array(
                        '@attributes' => array('content-type' => 'text/vcard', 'version' => '4.0'),
                    ),
                ),
                'd:supported-report-set' => array(
                    array('d:supported-report' => array('d:report' => array('card:addressbook-query' => null))),
                    array('d:supported-report' => array('d:report' => array('card:addressbook-multiget' => null))),
                ),
            ),
        );
    }

    private function build_contact_response(array $entry, $include_address_data) {
        $vcard    = $this->vcf->build_vcard($entry);
        $etag     = '"' . md5($vcard) . '"';
        $href     = wp_parse_url(home_url('/carddav/contacts/gf-' . (int) $entry['id'] . '.vcf'), PHP_URL_PATH);
        $properties = array(
            'd:getetag'        => $etag,
            'd:getcontenttype' => 'text/vcard; charset=utf-8',
            'd:resourcetype'   => null,
        );

        if ($include_address_data) {
            $properties['card:address-data'] = $vcard;
        }

        return array(
            'href'       => $href,
            'properties' => $properties,
        );
    }

    private function send_options() {
        status_header(200);
        header('Allow: OPTIONS, PROPFIND, REPORT, GET');
        header('DAV: 1, 3, addressbook');
        header('MS-Author-Via: DAV');
        exit;
    }

    private function send_multistatus(array $responses) {
        status_header(207);
        header('Content-Type: application/xml; charset=utf-8');

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $multistatus = $xml->createElementNS('DAV:', 'd:multistatus');
        $multistatus->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:card', 'urn:ietf:params:xml:ns:carddav');
        $multistatus->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cs', 'http://calendarserver.org/ns/');
        $xml->appendChild($multistatus);

        foreach ($responses as $response_data) {
            $response = $this->create_namespaced_element($xml, 'd:response');
            $href     = $this->create_namespaced_element($xml, 'd:href', $response_data['href']);
            $propstat = $this->create_namespaced_element($xml, 'd:propstat');
            $prop     = $this->create_namespaced_element($xml, 'd:prop');

            foreach ($response_data['properties'] as $name => $value) {
                $this->append_property($xml, $prop, $name, $value);
            }

            $status = $this->create_namespaced_element($xml, 'd:status', 'HTTP/1.1 200 OK');
            $propstat->appendChild($prop);
            $propstat->appendChild($status);
            $response->appendChild($href);
            $response->appendChild($propstat);
            $multistatus->appendChild($response);
        }

        $output = $xml->saveXML();
        header('Content-Length: ' . strlen($output));
        echo $output;
        exit;
    }

    private function append_property(DOMDocument $xml, DOMElement $parent, $qualified_name, $value) {
        if ($qualified_name === 'd:resourcetype' && $value === null) {
            $parent->appendChild($this->create_namespaced_element($xml, 'd:resourcetype'));
            return;
        }

        $element = $this->create_namespaced_element($xml, $qualified_name);
        $parent->appendChild($element);

        if (is_array($value)) {
            if ($this->is_list_array($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        foreach ($item as $child_name => $child_value) {
                            $this->append_property($xml, $element, $child_name, $child_value);
                        }
                    }
                }

                return;
            }

            foreach ($value as $child_name => $child_value) {
                if ($child_name === '@attributes' && is_array($child_value)) {
                    foreach ($child_value as $attribute_name => $attribute_value) {
                        $element->setAttribute($attribute_name, $attribute_value);
                    }
                    continue;
                }

                $this->append_property($xml, $element, $child_name, $child_value);
            }
            return;
        }

        if ($value !== null) {
            $element->appendChild($xml->createTextNode((string) $value));
        }
    }

    private function create_namespaced_element(DOMDocument $xml, $qualified_name, $text = null) {
        list($namespace, $name) = $this->resolve_namespace($qualified_name);
        $element = $xml->createElementNS($namespace, $name);

        if ($text !== null) {
            $element->appendChild($xml->createTextNode((string) $text));
        }

        return $element;
    }

    private function resolve_namespace($qualified_name) {
        $map = array(
            'd'    => 'DAV:',
            'card' => 'urn:ietf:params:xml:ns:carddav',
            'cs'   => 'http://calendarserver.org/ns/',
        );

        if (strpos($qualified_name, ':') === false) {
            return array('DAV:', $qualified_name);
        }

        list($prefix) = explode(':', $qualified_name, 2);

        return array(isset($map[$prefix]) ? $map[$prefix] : 'DAV:', $qualified_name);
    }

    /**
     * Check whether an array is a sequential (list) array.
     *
     * Handles empty arrays correctly (returns true) and uses the native
     * array_is_list() on PHP 8.1+.
     */
    private function is_list_array(array $value) {
        if (empty($value)) {
            return true;
        }

        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
