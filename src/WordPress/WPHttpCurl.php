<?php

namespace WordPress;

/**
 * HTTP request method uses Curl extension to retrieve the url.
 *
 * Requires the Curl extension to be installed.
 *
 * @package WordPress
 * @subpackage HTTP
 * @since 2.7.0
 */
class WPHttpCurl {

    /**
     * Temporary header storage for during requests.
     *
     * @since 3.2.0
     * @access private
     * @var string
     */
    private $headers = '';

    /**
     * Temporary body storage for during requests.
     *
     * @since 3.6.0
     * @access private
     * @var string
     */
    private $body = '';

    /**
     * The maximum amount of data to recieve from the remote server
     *
     * @since 3.6.0
     * @access private
     * @var int
     */
    private $max_body_length = false;

    /**
     * The file resource used for streaming to file.
     *
     * @since 3.6.0
     * @access private
     * @var resource
     */
    private $stream_handle = false;

    /**
     * Send a HTTP request to a URI using cURL extension.
     *
     * @access public
     * @since 2.7.0
     *
     * @param string $url
     * @param str|array $args Optional. Override the defaults.
     * @return array 'headers', 'body', 'response', 'cookies' and 'filename' keys.
     */
    function request($url, $args = array()) {
        $defaults = array(
            'method' => 'GET', 'timeout' => 5,
            'redirection' => 5, 'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(), 'body' => null, 'cookies' => array()
        );

        $r = wp_parse_args( $args, $defaults );

        if ( isset($r['headers']['User-Agent']) ) {
            $r['user-agent'] = $r['headers']['User-Agent'];
            unset($r['headers']['User-Agent']);
        } else if ( isset($r['headers']['user-agent']) ) {
            $r['user-agent'] = $r['headers']['user-agent'];
            unset($r['headers']['user-agent']);
        }

        // Construct Cookie: header if any cookies are set.
        WPHttp::buildCookieHeader( $r );

        $handle = curl_init();

        // cURL offers really easy proxy support.
        $proxy = new WPHTTPProxy();

        if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {

            curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            curl_setopt( $handle, CURLOPT_PROXY, $proxy->host() );
            curl_setopt( $handle, CURLOPT_PROXYPORT, $proxy->port() );

            if ( $proxy->use_authentication() ) {
                curl_setopt( $handle, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
                curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
            }
        }

        $is_local = isset($r['local']) && $r['local'];
        $ssl_verify = isset($r['sslverify']) && $r['sslverify'];
        if ( $is_local )
            $ssl_verify = apply_filters('https_local_ssl_verify', $ssl_verify);
        elseif ( ! $is_local )
            $ssl_verify = apply_filters('https_ssl_verify', $ssl_verify);

        // CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT expect integers. Have to use ceil since
        // a value of 0 will allow an unlimited timeout.
        $timeout = (int) ceil( $r['timeout'] );
        curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $timeout );
        curl_setopt( $handle, CURLOPT_TIMEOUT, $timeout );

        curl_setopt( $handle, CURLOPT_URL, $url);
        curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, ( $ssl_verify === true ) ? 2 : false );
        curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, $ssl_verify );
        curl_setopt( $handle, CURLOPT_CAINFO, $r['sslcertificates'] );
        curl_setopt( $handle, CURLOPT_USERAGENT, $r['user-agent'] );
        // The option doesn't work with safe mode or when open_basedir is set, and there's a
        // bug #17490 with redirected POST requests, so handle redirections outside Curl.
        curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
        if ( defined( 'CURLOPT_PROTOCOLS' ) ) // PHP 5.2.10 / cURL 7.19.4
            curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );

        switch ( $r['method'] ) {
            case 'HEAD':
                curl_setopt( $handle, CURLOPT_NOBODY, true );
                break;
            case 'POST':
                curl_setopt( $handle, CURLOPT_POST, true );
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $r['body'] );
                break;
            case 'PUT':
                curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, 'PUT' );
                curl_setopt( $handle, CURLOPT_POSTFIELDS, $r['body'] );
                break;
            default:
                curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $r['method'] );
                if ( ! is_null( $r['body'] ) )
                    curl_setopt( $handle, CURLOPT_POSTFIELDS, $r['body'] );
                break;
        }

        if ( true === $r['blocking'] ) {
            curl_setopt( $handle, CURLOPT_HEADERFUNCTION, array( $this, 'stream_headers' ) );
            curl_setopt( $handle, CURLOPT_WRITEFUNCTION, array( $this, 'stream_body' ) );
        }

        curl_setopt( $handle, CURLOPT_HEADER, false );

        if ( isset( $r['limit_response_size'] ) )
            $this->max_body_length = intval( $r['limit_response_size'] );
        else
            $this->max_body_length = false;

        // If streaming to a file open a file handle, and setup our curl streaming handler
        if ( $r['stream'] ) {
            if ( ! WP_DEBUG )
                $this->stream_handle = @fopen( $r['filename'], 'w+' );
            else
                $this->stream_handle = fopen( $r['filename'], 'w+' );
            if ( ! $this->stream_handle )
                return new WordPress\WPError( 'http_request_failed', sprintf( __( 'Could not open handle for fopen() to %s' ), $r['filename'] ) );
        } else {
            $this->stream_handle = false;
        }

        if ( !empty( $r['headers'] ) ) {
            // cURL expects full header strings in each element
            $headers = array();
            foreach ( $r['headers'] as $name => $value ) {
                $headers[] = "{$name}: $value";
            }
            curl_setopt( $handle, CURLOPT_HTTPHEADER, $headers );
        }

        if ( $r['httpversion'] == '1.0' )
            curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0 );
        else
            curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

        // Cookies are not handled by the HTTP API currently. Allow for plugin authors to handle it
        // themselves... Although, it is somewhat pointless without some reference.
        do_action_ref_array( 'http_api_curl', array(&$handle) );

        // We don't need to return the body, so don't. Just execute request and return.
        if ( ! $r['blocking'] ) {
            curl_exec( $handle );

            if ( $curl_error = curl_error( $handle ) ) {
                curl_close( $handle );
                return new \WordPress\WPError( 'http_request_failed', $curl_error );
            }
            if ( in_array( curl_getinfo( $handle, CURLINFO_HTTP_CODE ), array( 301, 302 ) ) ) {
                curl_close( $handle );
                return new \WordPress\WPError( 'http_request_failed', __( 'Too many redirects.' ) );
            }

            curl_close( $handle );
            return array( 'headers' => array(), 'body' => '', 'response' => array('code' => false, 'message' => false), 'cookies' => array() );
        }

        $theResponse = curl_exec( $handle );
        $theHeaders = WPHttp::processHeaders( $this->headers, $url );
        $theBody = $this->body;

        $this->headers = '';
        $this->body = '';

        $curl_error = curl_errno( $handle );

        // If an error occured, or, no response
        if ( $curl_error || ( 0 == strlen( $theBody ) && empty( $theHeaders['headers'] ) ) ) {
            if ( CURLE_WRITE_ERROR /* 23 */ == $curl_error &&  $r['stream'] ) {
                fclose( $this->stream_handle );
                return new \WordPress\WPError( 'http_request_failed', __( 'Failed to write request to temporary file.' ) );
            }
            if ( $curl_error = curl_error( $handle ) ) {
                curl_close( $handle );
                return new \WordPress\WPError( 'http_request_failed', $curl_error );
            }
            if ( in_array( curl_getinfo( $handle, CURLINFO_HTTP_CODE ), array( 301, 302 ) ) ) {
                curl_close( $handle );
                return new \WordPress\WPError( 'http_request_failed', __( 'Too many redirects.' ) );
            }
        }

        $response = array();
        $response['code'] = curl_getinfo( $handle, CURLINFO_HTTP_CODE );
        $response['message'] = get_status_header_desc($response['code']);

        curl_close( $handle );

        if ( $r['stream'] )
            fclose( $this->stream_handle );

        $response = array(
            'headers' => $theHeaders['headers'],
            'body' => null,
            'response' => $response,
            'cookies' => $theHeaders['cookies'],
            'filename' => $r['filename']
        );

        // Handle redirects
        if ( false !== ( $redirect_response = WPHttp::handle_redirects( $url, $r, $response ) ) )
            return $redirect_response;

        if ( true === $r['decompress'] && true === WPHttpEncoding::should_decode($theHeaders['headers']) )
            $theBody = WPHttpEncoding::decompress( $theBody );

        $response['body'] = $theBody;

        return $response;
    }

    /**
     * Grab the headers of the cURL request
     *
     * Each header is sent individually to this callback, so we append to the $header property for temporary storage
     *
     * @since 3.2.0
     * @access private
     * @return int
     */
    private function stream_headers( $handle, $headers ) {
        $this->headers .= $headers;
        return strlen( $headers );
    }

    /**
     * Grab the body of the cURL request
     *
     * The contents of the document are passed in chunks, so we append to the $body property for temporary storage.
     * Returning a length shorter than the length of $data passed in will cause cURL to abort the request as "completed"
     *
     * @since 3.6.0
     * @access private
     * @return int
     */
    private function stream_body( $handle, $data ) {
        $data_length = strlen( $data );

        if ( $this->max_body_length && ( strlen( $this->body ) + $data_length ) > $this->max_body_length )
            $data = substr( $data, 0, ( $this->max_body_length - $data_length ) );

        if ( $this->stream_handle ) {
            $bytes_written = fwrite( $this->stream_handle, $data );
        } else {
            $this->body .= $data;
            $bytes_written = $data_length;
        }

        // Upon event of this function returning less than strlen( $data ) curl will error with CURLE_WRITE_ERROR
        return $bytes_written;
    }

    /**
     * Whether this class can be used for retrieving an URL.
     *
     * @static
     * @since 2.7.0
     *
     * @return boolean False means this class can not be used, true means it can.
     */
    public static function test( $args = array() ) {
        if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) )
            return false;

        $is_ssl = isset( $args['ssl'] ) && $args['ssl'];

        if ( $is_ssl ) {
            $curl_version = curl_version();
            if ( ! (CURL_VERSION_SSL & $curl_version['features']) ) // Does this cURL version support SSL requests?
                return false;
        }

        return apply_filters( 'use_curl_transport', true, $args );
    }
}