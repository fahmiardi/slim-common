<?php
require(ROOT.'/common/idiorm.php');
require(ROOT.'/common/paris.php');
ORM::configure('mysql:host='.config('db1.host').';dbname='.config('db1.name').';port='.config('db1.port'));
ORM::configure('username', config('db1.user'));
ORM::configure('password', config('db1.pass'));
ORM::configure('logging', config('log.level', 0) >= 4);

class SlimModel extends Model {

  static function &getBy($field, $val, $flush = false) {
    if (is_null($field)) {
      throw new Exception("Arg #1 must be defined");
    }
    if (is_null($val)) {
      return false;
    }
    $cache_key = sprintf('%s-%s:%s', get_called_class(), $field, $val);
    if (!$flush && ( $cached = memget($cache_key) )) {
      return $cached;
    }
    $factory = Model::factory(get_called_class());
    if ($object = $factory->where($field, $val)->find_one()) {
      $object->onLoad();
    }
    self::cacheBy($field, $val, $object);
    return $object;
  }

  /**
   * This function is called by SlimModel::getBy after loading the
   * object from the database. Use it to perform any other operations
   * that should proceed caching.
   */
  function onLoad() {}

  static function cacheBy($field, $val, $object = null) {
    if (is_null($field)) {
      throw new Exception("Arg #1 must be defined");
    }
    if (is_null($val)) {
      return false;
    }
    $cache_key = sprintf('%s-%s:%s', get_called_class(), $field, $val);
    return is_null($object) ? memdel($cache_key) : memset($cache_key, $object);
  }

  /**
   * This function is called by SlimModel::apply
   */
  function flush() {
    self::cacheBy('id', $this->id(), null);
  }

  /**
   * This function is called by ApiMiddleware before an object's
   * data is sent across the wire. Subclasses of SlimModel should
   * use this function to remove data that isn't suitable for 
   * transmission and/or with respect for the current auth context.
   */
  function encode() {
    return $this->as_array();
  }

  /**
   * Override this function to provide for the default browsing
   * endpoint for any model, e.g., "api/(model)"
   * @return array
   */
  function _list($args) {
    return parse_args($args);
  }

  /**
   * Override this function to filter data that is applied to
   * this model by SlimModel::apply
   */
  function beforeApply($data) {
    return $data;
  }

  /**
   * Override this function to filter local data before it is
   * saved by SlimModel::apply
   */
  function beforeSave($data) {}

  /**
   * Override this function to perform actions after a model is
   * saved by SlimModel::apply
   */
  function afterSave($data) {}
    
  /**
   * Update this model with the given data packet.
   * @param mixed $data
   */
  function apply($data) {
    if ($data = $this->beforeApply(parse_args($data))) {
      foreach($data as $k => $v) {
        // ignore $id and any field beginning with an underscore
        if ($k !== 'id' && strpos($k, '_') !== 0) {
          $this->$k = $v;
        }
      }
    }
    $this->beforeSave($data);
    $this->save();
    $this->afterSave($data);
    $this->flush();
    return $this;
  }

}

/**
 * lib autoloader, looks in ROOT/lib then ROOT/common/lib
 */
spl_autoload_register(function($className) {
  $commonLib = __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR;
  $siteLib = realpath(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib').DIRECTORY_SEPARATOR;

  foreach(array($siteLib, $commonLib) as $baseDir) {
    $className = ltrim($className, '\\');
    $fileName  = $baseDir;
    $namespace = '';
    if ($lastNsPos = strripos($className, '\\')) {
      $namespace = substr($className, 0, $lastNsPos);
      $className = substr($className, $lastNsPos + 1);
      $fileName  .= str_replace('\\', DIRECTORY_SEPARATOR, strtolower($namespace)) . DIRECTORY_SEPARATOR;
    }
    $fileName .= str_replace('_', DIRECTORY_SEPARATOR, strtolower($className)) . '.php';

    if (file_exists($fileName)) {
      return require($fileName);
    }
  }
});

/**
 * Parse the given input into an array, and then backfill
 * with the contents of $defaults, if given.
 * @param mixed $in Accepts querystrings, arrays, and objects
 * @param mixed $defaults Accepts querystrings, arrays, and objects, or simply null
 * @return array Hashmap of input
 */
function parse_args($in, $defaults = null) {
  if (!$in && !$defaults) {
    return array();
  } else if (!$in) {
    return $defaults;
  }
  
  if (is_array($in)) {
    $in_arr = $in;
  } else if (is_object($in)) {
    $in_arr = get_object_vars($in);
  } else {
    parse_str($in, $in_arr);
  }

  if (!is_array($in_arr)) {
    throw new Exception("Failed to parse String input into an array: ".$in);
  }

  if (!is_null($defaults)) {
    $defaults = parse_args($defaults);
  }
  
  return $defaults && count($defaults) ? array_merge($defaults, $in_arr) : $in_arr;
}

/**
 * Unserialize value only if it was serialized.
 *
 * @param string $original Maybe unserialized original, if is needed.
 * @return mixed Unserialized data can be any type.
 */
function maybe_unserialize($original) {
  if (is_serialized($original)) { // don't attempt to unserialize data that wasn't serialized going in
    return @unserialize($original);
  }
  return $original;
}

/**
 * Check value to find if it was serialized.
 *
 * If $data is not an string, then returned value will always be false.
 * Serialized data is always a string.
 *
 * @param mixed $data Value to check to see if was serialized.
 * @return bool False if not serialized and true if it was.
 */
function is_serialized($data) {
  // if it isn't a string, it isn't serialized
  if (!is_string($data)) {
    return false;
  }
  $data = trim($data);
  if ('N;' == $data) {
    return true;
  }
  if (!preg_match('/^([adObis]):/', $data, $badions)) {
    return false;
  }
  switch ($badions[1]) {
    case 'a' :
    case 'O' :
    case 's' :
      if (preg_match( "/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
        return true;
      }
      break;
    case 'b' :
    case 'i' :
    case 'd' :
      if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
        return true;
      }
      break;
  }
  return false;
}

/**
 * Serialize data, if needed.
 *
 * @param mixed $data Data that might be serialized.
 * @return mixed A scalar data
 */
function maybe_serialize($data) {
  if (is_array($data) || is_object($data)) {
    return serialize( $data );
  }    
  if (is_serialized($data)) {
    return serialize($data);
  }    
  return $data;
}