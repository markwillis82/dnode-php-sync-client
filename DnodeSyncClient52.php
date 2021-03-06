<?php
/**
 * Dnode Synchronous Client for PHP
 *
 * @copyright 2012 erasys GmbH - see ./LICENSE.txt for more info
 */

/**
 * Base class for all exceptions thrown by this library.
 */
class BaseException extends RuntimeException {}

/**
 * Thrown in case of network error
 */
class IOException extends BaseException {}

/**
 * Thrown in case remote response can't be parsed
 */
class ProtocolException extends BaseException {}

/**
 * Thrown if client of this library calls method not declared by remote
 */
class MethodNotExistsException extends BaseException {}

/**
 * Thrown when calling method on closed connection
 */
class ConnectionClosedException extends BaseException {}

/**
 * Main Dnode client class
 *
 * This is the only class you should instantiate directly from your code.
 */
class Dnode {

  /**
   * Creates new dnode connection to given host and port
   *
   * @param type $host
   * @param type $port
   *
   * @return \DnodeSyncClient\Connection
   *
   * @throws \DnodeSyncClient\IOException
   * @throws \DnodeSyncClient\ProtocolException
   */
  public function connect($host, $port, $connectTimeout=false) {
    $address = "tcp://$host:$port";
    if(!$connectTimeout) {
      @$stream = stream_socket_client($address, $errNo, $errStr);
    } else {
      @$stream = stream_socket_client($address, $errNo, $errStr, $connectTimeout);
    }
    if (!$stream) {
      throw new IOException("Can't create socket to $address. Error: $errNo) $errStr");
    }

    if($connectTimeout) {
      stream_set_timeout($stream, $connectTimeout);
    }

    return new Connection($stream);
  }

}


/**
 * Connection to dnode service
 */
class Connection {

  private $stream;
  private $methods;
  private $callbackNumber = 41; // lets start from some higher number to make
							   // sure that remote is using our callback numbers
  private $closed = false;

  /**
   * Initializes connect on given stream
   *
   * Do not use directly if you know host and port of dnode service, rather use
   * \DnodeSyncClient\Dnode::connect
   *
   * @param resource $stream
   *
   * @throws \DnodeSyncClient\IOException
   * @throws \DnodeSyncClient\ProtocolException
   */
  public function __construct($stream) {
    $this->stream = $stream;

    // write our (empty) methods description
    fputs($this->stream, json_encode(array("method" => "methods")) ."\n");

    // read remote methods
    $line = fgets($this->stream);
    if ($line === false) {
      throw new IOException("Can't read method description from remote");
    }
    $line = trim($line);
    $methods = json_decode($line, true);
    if ($methods === null) {
      throw new ProtocolException("First line is not valid json: $line");
    }
    if (!isset($methods['method'])) {
      throw new ProtocolException("First line does not have method field: $line");
    }
    if ($methods['method'] !== 'methods') {
      throw new ProtocolException("First line method must be \"methods\": $line");
    }
    if (!isset($methods['arguments'])) {
      throw new ProtocolException("Methods arguments missing: $line");
    }
    if (count($methods['arguments']) != 1) {
      throw new ProtocolException("Methods must have single argument: $line");
    }

    $this->methods = array_keys($methods['arguments'][0]);
    if (count($this->methods) == 0) {
      throw new ProtocolException("Remote is expected to have some methods: $line");
    }
  }

  /**
   * Calls method on this dnode connection
   *
   * @param string $method Method name
   * @param array $arguments Arguments
   *
   * @return array Response arguments as array.
   *
   * @throws \DnodeSyncClient\MethodNotExistsException Thrown if remote does not declare called method.
   * @throws \DnodeSyncClient\IOException Thrown in case of network error
   * @throws \DnodeSyncClient\ProtocolException Thrown if remote answer does not have supported format.
   */
  public function call($method, array $arguments = array()) {
    if ($this->closed) {
      throw new ConnectionClosedException();
    }

    if (!in_array($method, $this->methods)) {
      throw new MethodNotExistsException("Method $method does not exists on remote.");
    }
    $callbacks = new stdclass();
    $callbacks->{++$this->callbackNumber} = array(count($arguments));

    fwrite($this->stream, json_encode(array(
      'method' => $method,
      'arguments' => $arguments,
      'callbacks' => $callbacks,
    )) . "\n");

    // this will block the stream until response is read
    $line = fgets($this->stream);
    if ($line === false) {
      $this->close();
      throw new IOException("Can't read response from remote");
    }
    $line = trim($line);
    $message = json_decode($line, true);
    if ($message === null) {
      throw new ProtocolException("Response is not valid json: $line");
    }
    if (!isset($message['method'])) {
      throw new ProtocolException("Response does not have method field: $line");
    }
    if ($message['method'] !== $this->callbackNumber) {
      throw new ProtocolException("Response does not call expected callback, expected "
      . $this->callbackNumber . ", got $line");
    }
    if (isset($message['links']) && $message['links']) {
      throw new ProtocolException("Response contains links, we do not support that: $line");
    }
    if (isset($message['callbacks']) && $message['callbacks']) {
      throw new ProtocolException("Response contains callbacks, we do not support that: $line");
    }
    if (!array_key_exists('arguments', $message)) {
      return array();
    }
    if (!is_array($message['arguments'])) {
      throw new ProtocolException("Response arguments must be array: $line");
    }
    return $message['arguments'];
  }

  /**
   * Lists methods available by remote dnode service
   *
   * @return array
   */
  public function getAvailableMethods() {
    return $this->methods;
  }

  /**
   * Closes this connection
   */
  public function close() {
    fclose($this->stream);
    $this->closed = true;
  }

}

