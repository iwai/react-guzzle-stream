<?php
/**
 * Stream.php
 *
 * @copyright   Copyright (c) 2014 sonicmoov Co.,Ltd.
 * @version     $Id$
 *
 */


namespace Iwai\React\Guzzle;

use Evenement\EventEmitter;
use GuzzleHttp\Stream\PumpStream;
use GuzzleHttp\Stream\StreamInterface;
use React\Stream\ReadableStreamInterface;

use React\EventLoop\LoopInterface;
use React\Stream\Buffer;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

class Stream extends EventEmitter implements StreamInterface, ReadableStreamInterface {


    public $bufferSize = 4096;
    protected $closing = false;
    protected $loop;
    protected $buffer;

    private $stream;
    private $size;
    private $seekable;
    private $readable = true;
    private $writable = true;
    private $uri;
    private $customMetadata;

    private $blocking = true;

    /** @var array Hash of readable and writable stream types */
    private static $readWriteHash = [
        'read' => [
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true,
            'c+b' => true, 'rt' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a+' => true
        ],
        'write' => [
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true,
            'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true,
            'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true
        ]
    ];

    /**
     * Create a new stream based on the input type.
     *
     * This factory accepts the same associative array of options as described
     * in the constructor.
     *
     * @param resource|string|StreamInterface $resource Entity body data
     * @param array                           $options  Additional options
     *
     * @return Stream
     * @throws \InvalidArgumentException if the $resource arg is not valid.
     */
    public static function factory($resource = '', array $options = [])
    {
        $type = gettype($resource);

        if ($type == 'string') {
            $stream = fopen('php://temp', 'r+');
            if ($resource !== '') {
                fwrite($stream, $resource);
                fseek($stream, 0);
            }
            return new self($stream, $options);
        }

        if ($type == 'resource') {
            return new self($resource, $options);
        }

        if ($resource instanceof StreamInterface) {
            return $resource;
        }

        if ($type == 'object' && method_exists($resource, '__toString')) {
            return self::factory((string) $resource, $options);
        }

        if (is_callable($resource)) {
            return new PumpStream($resource, $options);
        }

        if ($resource instanceof \Iterator) {
            return new PumpStream(function () use ($resource) {
                if (!$resource->valid()) {
                    return false;
                }
                $result = $resource->current();
                $resource->next();
                return $result;
            }, $options);
        }

        throw new \InvalidArgumentException('Invalid resource type: ' . $type);
    }


    /**
     * This constructor accepts an associative array of options.
     *
     * - size: (int) If a read stream would otherwise have an indeterminate
     *   size, but the size is known due to foreknownledge, then you can
     *   provide that size, in bytes.
     * - metadata: (array) Any additional metadata to return when the metadata
     *   of the stream is accessed.
     *
     * @param resource      $stream  Stream resource to wrap.
     * @param array         $options Associative array of options.
     * @param LoopInterface $loop    Event loop
     *
     * @throws \InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($stream, $options = [], LoopInterface $loop)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== "stream") {
            throw new \InvalidArgumentException('First parameter must be a valid stream resource');
        }
        $this->stream = $stream;
        $this->loop   = $loop;

        if (isset($options['size'])) {
            $this->size = $options['size'];
        }

        $this->customMetadata = isset($options['metadata'])
            ? $options['metadata']
            : [];

        $this->attach($stream);

        $this->buffer = new Buffer($this->stream, $this->loop);

        $this->buffer->on('error', function ($error) {
            $this->emit('error', array($error, $this));
            $this->close();
        });

        $this->buffer->on('drain', function () {
            $this->emit('drain', array($this));
        });
    }

    public function on($event, callable $listener)
    {
        // Dynamic non-blocking mode
        if ($this->blocking) {
            stream_set_blocking($this->stream, 0);
            $this->blocking = false;

            $this->resume();
        }

        parent::on($event, $listener);
    }

    /**
     * Closes the stream when the destructed
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Returns whether or not the stream is writable
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->writable;
    }

    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * Returns whether or not the stream is seekable
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->seekable;
    }


    public function pause()
    {
        $this->loop->removeReadStream($this->stream);
    }

    public function resume()
    {
        if ($this->readable) {
            $this->loop->addReadStream($this->stream, array($this, 'handleData'));
        }
    }

    public function handleData($stream)
    {
        $data = fread($stream, $this->bufferSize);

        $this->emit('data', array($data, $this));

        if (!is_resource($stream) || feof($stream)) {
            $this->end();
        }
    }

    /**
     * Write data to the stream
     *
     * @param string $string The string that is to be written.
     *
     * @return int|bool Returns the number of bytes written to the stream on
     *                  success returns false on failure (e.g., broken pipe,
     *                  writer needs to slow down, buffer is full, etc.)
     */
    public function write($string)
    {
        if (!$this->writable)
            return;

        // Dynamic non-blocking mode
        if ($this->blocking) {
            stream_set_blocking($this->stream, 0);
            $this->blocking = false;

            $this->resume();
        }

        return $this->buffer->write($string);
    }

    public function end($data = null)
    {
        if (!$this->writable) {
            return;
        }

        $this->closing = true;

        $this->readable = false;
        $this->writable = false;

        $this->buffer->on('close', function () {
            $this->close();
        });

        $this->buffer->end($data);
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    /**
     * Closes the stream and any underlying resources.
     */
    public function close()
    {
        if (!$this->writable && !$this->closing) {
            return;
        }

        $this->closing = false;

        $this->readable = false;
        $this->writable = false;

        $this->emit('end', array($this));
        $this->emit('close', array($this));
        $this->loop->removeStream($this->stream);
        $this->buffer->removeAllListeners();
        $this->removeAllListeners();

        $this->handleClose();
    }

    public function handleClose()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }


    /**
     * Replaces the underlying stream resource with the provided stream.
     *
     * Use this method to replace the underlying stream with another; as an
     * example, in server-side code, if you decide to return a file, you
     * would replace the original content-oriented stream with the file
     * stream.
     *
     * Any internal state such as caching of cursor position should be reset
     * when attach() is called, as the stream has changed.
     *
     * @param resource $stream
     *
     * @return void
     */
    public function attach($stream)
    {
        $meta = stream_get_meta_data($this->stream);
        $this->seekable = $meta['seekable'];
        $this->uri = $this->getMetadata('uri');
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the underlying resource has been detached, the stream object is in
     * an unusable state. If you wish to use a Stream object as a PHP stream
     * but keep the Stream object in a consistent state, use
     * {@see GuzzleHttp\Stream\GuzzleStreamWrapper::getResource}.
     *
     * @return resource|null Returns the underlying PHP stream resource or null
     *                       if the Stream object did not utilize an underlying
     *                       stream resource.
     */
    public function detach()
    {
        $result = $this->stream;
        $this->stream = $this->size = $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;

        return $result;
    }

    public function setSize($size)
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Get the size of the stream if known
     *
     * @return int|null Returns the size in bytes if known, or null if unknown
     */
    public function getSize()
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (!$this->stream || !$this->blocking) {
            return null;
        }

        // Clear the stat cache if the stream has a URI
        if ($this->uri) {
            clearstatcache(true, $this->uri);
        }

        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }

        return null;
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int|bool Returns the position of the file pointer or false on error
     */
    public function tell()
    {
        return $this->stream ? ftell($this->stream) : false;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return !$this->stream || feof($this->stream);
    }

    /**
     * Seek to a position in the stream
     *
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *                    based on the seek offset. Valid values are identical
     *                    to the built-in PHP $whence values for `fseek()`.
     *                    SEEK_SET: Set position equal to offset bytes
     *                    SEEK_CUR: Set position to current location plus offset
     *                    SEEK_END: Set position to end-of-stream plus offset
     *
     * @return bool Returns true on success or false on failure
     * @link   http://www.php.net/manual/en/function.fseek.php
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->seekable
            ? fseek($this->stream, $offset, $whence) === 0
            : false;
    }

    /**
     * Read data from the stream
     *
     * @param int $length Read up to $length bytes from the object and return
     *                    them. Fewer than $length bytes may be returned if
     *                    underlying stream call returns fewer bytes.
     *
     * @return string     Returns the data read from the stream.
     */
    public function read($length)
    {
        return ($this->readable && $this->blocking) ? fread($this->stream, $length) : false;
    }

    /**
     * Attempts to seek to the beginning of the stream and reads all data into
     * a string until the end of the stream is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->stream || !$this->blocking) {
            return '';
        }

        $this->seek(0);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * Returns the remaining contents of the stream as a string.
     *
     * Note: this could potentially load a large amount of data into memory.
     *
     * @return string
     */
    public function getContents()
    {
        return ($this->stream && $this->blocking) ? stream_get_contents($this->stream) : '';
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @param string $key Specific metadata to retrieve.
     *
     * @return array|mixed|null Returns an associative array if no key is
     *                          no key is provided. Returns a specific key
     *                          value if a key is provided and the value is
     *                          found, or null if the key is not found.
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     */
    public function getMetadata($key = null)
    {
        if (!$this->stream) {
            return $key ? null : [];
        } elseif (!$key) {
            return $this->customMetadata + stream_get_meta_data($this->stream);
        } elseif (isset($this->customMetadata[$key])) {
            return $this->customMetadata[$key];
        }

        $meta = stream_get_meta_data($this->stream);

        return isset($meta[$key]) ? $meta[$key] : null;
    }

    public function getBuffer()
    {
        return $this->buffer;
    }

}