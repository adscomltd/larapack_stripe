<?php

namespace Adscom\LarapackStripe\Helpers;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class ArrayUtils
{
  public static function flatten(array $data): array
  {
    $newData = [];
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        foreach (self::convertMultidimensionalArrayToSingle($value, $key) as $k => $v) {
          $newData[$k] = $v;
        }
      } else {
        $newData[$key] = $value;
      }
    }

    return $newData;
  }

  private static function convertMultidimensionalArrayToSingle(array $arr, string $prefix = ''): array
  {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveArrayIterator($arr),
      RecursiveIteratorIterator::SELF_FIRST
    );
    $path = [];
    $flatArray = [];

    foreach ($iterator as $key => $value) {
      $path[$iterator->getDepth()] = $key;

      if (!is_array($value)) {
        $flatArray[$prefix.'.'.implode('.', array_slice($path, 0, $iterator->getDepth() + 1))] = $value;
      }
    }

    return $flatArray;
  }
}

