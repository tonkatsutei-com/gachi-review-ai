<?php
/**
 * Div PHP Functions
 * 
 * A collection of standalone functions designed to enhance PHP capabilities,
 * providing common utilities without external dependencies. 
 * Part of the divengine* ecosystem, these functions offer atomic 
 * solutions that PHP lacks natively.
 * 
 * @package divengine/functions
 * @author  Rafa Rodriguez @rafageist [https://rafageist.com]
 * @version 1.0.1
 *
 * @link    https://divengine.org
 * @link    https://github.com/divengine/functions
 */
namespace divengine;
use Exception;
use DateTime;
use DateTimeZone;
use ArrayObject;
use stdClass;
function is_url(string $url): bool
{
	return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
function is_closure(mixed $var): bool
{
	return $var instanceof \Closure;
}
function is_not_closure(mixed $var): bool
{
	return !is_closure($var);
}
function is_valid_date(string $date, string $format = 'Y-m-d'): bool
{
	$dateTime = DateTime::createFromFormat($format, $date);
	if ($dateTime && $dateTime->format($format) === $date) {
		$year = (int) $dateTime->format('Y');
		$month = (int) $dateTime->format('m');
		$day = (int) $dateTime->format('d');
		return checkdate($month, $day, $year);
	}
	return false;
}
function is_uuid(string $uuid): bool
{
	return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid) === 1;
}
function is_ISO8601(string $date): bool
{
	$pattern = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|([+-])(\d{2}):(\d{2}))$/';
	return preg_match($pattern, $date) === 1;
}
function is_array_of_uuid(?array $uuids): bool
{
	if ($uuids === null || empty($uuids)) {
		return false;
	}
	foreach ($uuids as $uuid) {
		if (!is_uuid($uuid)) {
			return false;
		}
	}
	return true;
}
function is_email(string $email): bool
{
	return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
function is_hex_color(string $color): bool
{
	return preg_match('/^#([0-9A-F]{6}|[0-9A-F]{3})$/i', $color) === 1;
}
function is_usdt(string $value): bool
{
	return preg_match('/^T[A-Za-z0-9]{33}$/', $value) === 1;
}
function is_boolean($value): bool
{
	if (is_bool($value)) {
		return true;
	}
	if (is_string($value)) {
		$normalizedValue = lower($value);
		return in_array($normalizedValue, ['true', 'false'], true);
	}
	return false;
}
function is_upper(string $value): bool
{
	return ctype_upper($value);
}
function is_lower(string $value): bool
{
	return ctype_lower($value);
}
function something_or_null($value): mixed
{
	return (is_null($value) || empty($value)) ? null : $value;
}
function boolean(mixed $value): bool
{
	if (is_bool($value)) {
		return $value;
	}
	if (is_string($value)) {
		$value = trim(lower($value));
	}
	if ($value === 'true' || $value === '1' || $value === 't' || $value === 1) {
		return true;
	}
	if ($value === 'false' || $value === '0' || $value === 'f') {
		return false;
	}
	return boolval($value);
}
function string(mixed $value, callable|bool $criteria = null): string
{
	if ($criteria !== null) {
		if (is_callable($criteria)) {
			if (!$criteria($value)) {
				return '';
			}
		} else {
			if (!$criteria) {
				return '';
			}
		}
	}
	if (is_string($value)) {
		return $value;
	}
	if (is_numeric($value)) {
		return strval($value);
	}
	if (is_bool($value)) {
		return $value ? 'true' : 'false';
	}
	if (is_object($value) && method_exists($value, '__toString')) {
		return $value->__toString();
	}
	if (is_array($value) || is_object($value)) {
		$result = json_encode($value);
		if ($result !== false) {
			return $result;
		}
	}
	return '';
}
function string_nullable(mixed $value): ?string
{
	if (!(empty($value) && $value !== '0' && $value !== 0)) {
		return null;
	}
	return string($value);
}
function teaser(string $text, int $limit): string
{
	$text = strip_tags(string($text));
	$text = string(preg_replace('/\s+/', ' ', $text));
	$text = trim($text);
	if (mb_strlen($text) <= $limit) {
		return $text;
	}
	$cutText = mb_substr($text, 0, $limit);
	$lastSpace = mb_strrpos($cutText, ' ');
	if ($lastSpace !== false) {
		$cutText = mb_substr($cutText, 0, $lastSpace);
	}
	$pattern = '/(http[s]?:\/\/[^\s]+|@[^\s]+|#[^\s]+)/u';
	preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
	foreach ($matches[0] as $match) {
		$startPos = $match[1];
		$endPos = $startPos + mb_strlen($match[0]);
		if ($startPos < $limit && $endPos > $limit) {
			$cutText = mb_substr($text, 0, $startPos);
			break;
		}
	}
	return trim($cutText) . '...';
}
function contains_sensitive(?string $string, ?string $search): bool
{
	$string = $string ?? '';
	$search = $search ?? '';
	return mb_strpos($string, $search) !== false;
}
function contains(?string $string, ?string $search): bool
{
	$string = $string ?? '';
	$search = $search ?? '';
	return mb_stripos($string, $search) !== false;
}
function upper(?string $value): string
{
	if ($value === null) {
		return '';
	}
	return mb_strtoupper(string($value));
}
function lower(?string $value): string
{
	if ($value === null) {
		return '';
	}
	return mb_strtolower($value);
}
function upper_nullable(?string $value): ?string
{
	if ($value === null) {
		return null;
	}
	return mb_strtoupper($value);
}
function lower_nullable(?string $value): ?string
{
	if ($value === null) {
		return null;
	}
	return mb_strtolower($value);
}
function trim_or_null(?string $value): ?string
{
	if ($value === null) {
		return null;
	}
	$trimmed = null_if_empty(trimer($value));
	return $trimmed == null ? null : string($trimmed);
}
function integer_array($array): array
{
	if (!is_array($array)) {
		return [];
	}
	return array_map('intval', $array);
}
function split_comma_separated_ints(?string $string): array
{
	if (empty($string)) {
		return [];
	}
	return array_map('intval', array_map('trim', explode(',', $string)));
}
function string_array(?array $value): ?array
{
	if ($value === null || empty($value)) {
		return null;
	}
	$value = array_map(fn ($item) => string($item), $value);
	return $value;
}
function string_array_nullable(?array $value): ?array
{
	if ($value === null || empty($value)) {
		return null;
	}
	return array_map(function ($item) {
		try {
			return string_nullable($item);
		} catch (Exception $e) {
			return null;
		}
	}, $value);
}
function array_filter_stringable(?array $value): ?array
{
	if ($value === null) {
		return null;
	}
	$filteredArray = array_filter($value, function ($item) {
		return is_scalar($item) || is_object($item) && method_exists($item, '__toString');
	});
	if (empty($filteredArray)) {
		return null;
	}
	return $filteredArray;
}
function int_or_min(array|bool|float|int|string|null $value, int $min): int
{
	$intValue = intval($value);
	return ($intValue < $min) ? $min : $intValue;
}
function int_or_max(array|bool|float|int|string|null $value, int $max): int
{
	$intValue = intval($value);
	return ($intValue > $max) ? $max : $intValue;
}
function int_or_default(array|bool|float|int|string|null $value, ?int $default = 0): ?int
{
	if (empty($value) && $value !== '0' && $value !== 0) {
		return $default;
	}
	return intval($value);
}
function int_or_null(array|bool|float|int|string|null $value): ?int
{
	return int_or_default($value, null);
}
function non_zero_or_default(int $value, ?int $default = -1): ?int
{
	return ($value === 0) ? $default : $value;
}
function non_zero_or_null(int $value): ?int
{
	return non_zero_or_default($value, null);
}
function numeric_or_default($value, ?int $default = 0): ?int
{
	return is_numeric($value) ? intval($value) : $default;
}
function numeric_or_null($value): ?int
{
	return numeric_or_default($value, null);
}
function float_or_default(array|bool|float|int|string|null $value, ?float $default = 0.0): ?float
{
	if (empty($value) && $value !== '0' && $value !== 0 && $value !== 0.0) {
		return $default;
	}
	return floatval($value);
}
function float_or_null(array|bool|float|int|string|null $value): ?float
{
	return float_or_default($value, null);
}
function str_safe_replace_sensitive(mixed $input, mixed $search, mixed $replace): string
{
	$search = string($search);
	$replace = string($replace);
	$input = string($input);
	return str_replace($search, $replace, $input);
}
function str_safe_replace(mixed $input, mixed $search, mixed $replace): string
{
	$search = string($search);
	$replace = string($replace);
	$input = string($input);
	return str_ireplace($search, $replace, $input);
}
function array_replace_values(array $array, $search, $replace)
{
	foreach ($array as $key => $value) {
		$array[$key] = $value == $search ? $replace : $value;
	}
	return $array;
}
function array_replace_values_strict(array $array, $search, $replace)
{
	foreach ($array as $key => $value) {
		$array[$key] = $value === $search ? $replace : $value;
	}
	return $array;
}
function convert_utc_to_local_time(string $utc_date, string $timezone = 'America/New_York'): string
{
	$dateObj = new DateTime($utc_date, new DateTimeZone('UTC'));  
	$dateObj->setTimezone(new DateTimeZone($timezone));  
	return $dateObj->format('Y-m-d\TH:i:sP');  
}
function datetime_to_date(string $datetime): string
{
	try {
		$datetime = new DateTime($datetime);
		return $datetime->format('Y-m-d');
	} catch (Exception $e) {
		return "Invalid datetime";
	}
}
function false($value): bool
{
	return !boolean($value);
}
function true($value): bool
{
	return boolean($value);
}
function not($value): bool
{
	return false($value);
}
function is_true($value): bool
{
	return true($value);
}
function is_false($value): bool
{
	return !false($value);
}
function cop(mixed &$source, mixed $complement, int $level = 0, bool $strict = false, \ReflectionProperty $propertyType = null)
{
	$null = null;
	if (is_null($source)) {
		return $complement;
	}
	if (is_null($complement)) {
		return $source;
	}
	if (is_scalar($source) && is_scalar($complement)) {
		return $complement;
	}
	if (is_scalar($complement) || is_scalar($source)) {
		return $source;
	}
	if ($level < 100) { 
		if (is_object($complement)) {
			$complement = get_object_vars($complement);
		}
		if (is_iterable($complement)) {
			foreach ($complement as $key => $value) {
				if (is_object($source)) {
					if (property_exists($source, $key)) {
						$property = new \ReflectionProperty($source, $key);
						$property->setAccessible(true);
						if (!$property->isInitialized($source)) {
							$type = $property->getType();
							$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
							$defaultValue = $type?->allowsNull() ? null : construct($typeName);
							$property->setValue($source, $defaultValue);
						}
						$propertyValue = $property->getValue($source);
						if (is_object($propertyValue) && is_object($value)) {
							cop($propertyValue, $value, $level + 1, $strict, $property);
						} else {
							$propertyType = $property->getType();
							$property->setValue($source, cop($propertyValue, $value, $level + 1, $strict, $property));
						}
					} else {
						if (!$strict) {
							$source->$key = cop($null, $value, $level + 1, $strict);
						}
					}
				}
				if (is_array($source)) {
					$setted =  false;
					if ($propertyType !== null) {
						if ($propertyType instanceof \ReflectionProperty) {
							$docComment = $propertyType->getDocComment();
							if ($docComment) {
								$arrayElementType = array_type($docComment);
								if (class_exists($arrayElementType)) {
									$source[$key] = new $arrayElementType();
									cop($source[$key], $value, $level + 1, $strict, $propertyType);
									$setted = true;
								}
							}
						}
					}
					if (!$setted) {
						if (array_key_exists($key, $source)) {
							$source[$key] = cop($source[$key], $value, $level + 1, $strict);
						} else {
							if (!$strict) {
								$source[$key] = cop($null, $value, $level + 1, $strict);
							}
						}
					}
				}
			}
		}
	}
	return $source;
}
function map(mixed $source, callable|array|object $map): mixed
{
	if ($source === null) {
		$source = new stdClass();
	}
	if (is_array($source)) {
		return array_map(fn ($obj) => map($obj, $map), $source);
	}
	if (is_callable($map)) {
		return $map($source);
	}
	$newObject = new stdClass();
	if (is_object($map)) {
		$newObject = clone $map;
		$map = array_keys((array) $map);
	}
	foreach ($map as $key => $value) {
		if (is_int($key)) {
			$key = $value;
		}
		if (is_callable($value)) {
			$passValue = null;
			if (isset($source->$key)) {
				$passValue = $source->$key;
			}
			$newObject->$key = $value($source, $passValue);
		} else if (is_string($value)) {
			if (is_object($source) && property_exists($source, $value)) {
				$newObject->$key = $source->$value;
			} else {
				$newObject->$key = null;
			}
		} else {
			$newObject->$key = $value;
		}
	}
	return $newObject;
}
function object_or_null($value): ?object
{
	return is_object($value) ? $value : null;
}
function division(float $a, float $b): ?float
{
	if ($b == 0) {
		return null;  
	}
	return $a / $b;
}
function first_not_empty(mixed ...$values): mixed
{
	foreach ($values as $value) {
		if (!empty($value)) {
			return $value;
		}
	}
	return null;
}
function in($haystack, mixed $value): bool
{
	if (search($haystack, $value) === false) {
		return false;
	}
	return true;
}
function uuidv4(): string
{
	$uuid = bin2hex(random_bytes(18));
	$uuid[8] = $uuid[13] = $uuid[18] = $uuid[23] = '-';
	$uuid[14] = '4';
	$uuid[19] = [
		'8', '9', 'a', 'b', '8', '9',
		'a', 'b', 'c' => '8', 'd' => '9',
		'e' => 'a', 'f' => 'b'
	][$uuid[19]] ?? $uuid[19];
	return $uuid;
}
function construct(?string $typeName): mixed
{
	if (empty($typeName)) {
		return null;
	}
	if (class_exists($typeName)) {
		return new $typeName();
	}
	return match ($typeName) {
		'int' => 0,
		'float' => 0.0,
		'bool' => false,
		'string' => '',
		'array' => [],
		'object' => (object) [],
		default => null
	};
}
function array_type(string $arrayType): string
{
	if (preg_match('/@var\s+(?:array<([^>\s]+)>\s*|\s*([^>\s]+)\[\]\s*)/', $arrayType, $matches)) {
		return $matches[1] ?: $matches[2];
	}
	return $arrayType;
}
function url_nullable(?string $url): ?string
{
	$original = $url;
	if ($url === null) {
		return null;
	}
	if (!is_url($url)) {
		$url = 'http://' . $original;
	}
	if (is_url($url)) {
		return $url;
	}
	return null;
}
function null_if_empty($valueToTest, $valueToReturn = null)
{
	if (empty($valueToTest) && $valueToTest !== '0' && $valueToTest !== 0 && $valueToTest !== 0.0) {
		return null;
	}
	if ($valueToReturn instanceof \Closure) {
		return $valueToReturn($valueToTest);
	}
	return $valueToReturn ?? $valueToTest;
}
function clean_phone_number(?string $value, int $length = 10): ?string
{
	if ($value === null) return null;
	$value = preg_replace('/\D/', '', $value);
	return substr(string($value), 0, $length);
}
function len($value): int
{
	if (empty($value) && $value !== 0) {
		return 0;
	}
	if (is_string($value)) {
		return mb_strlen($value, 'UTF-8');
	}
	if (is_array($value)) {
		return count($value);
	}
	if (is_numeric($value)) {
		return strlen("$value");
	}
	if (is_object($value)) {
		$a = new ArrayObject($value);
		return $a->count();
	}
	if (is_bool($value)) {
		return 1;
	}
	return 0;
}
function remove_accents(string $value, bool $includeNTilde = false): string
{
	$search = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü'];
	$replace = ['a', 'e', 'i', 'o', 'u', 'u', 'A', 'E', 'I', 'O', 'U', 'U'];
	if ($includeNTilde) {
		$search[] = 'ñ';
		$replace[] = 'n';
		$search[] = 'Ñ';
		$replace[] = 'N';
	}
	return strtr($value, array_combine($search, $replace));
}
function divide(mixed $value): array
{
	return match (true) {
		is_null($value) => [],
		is_string($value) => mb_str_split($value),
		is_array($value) => $value,
		is_object($value) => (array) $value,
		is_numeric($value) => mb_str_split("$value"),
		is_bool($value) => $value ? [true] : [false],
		default => []
	};
}
function has_non_zero_decimal(float $number): bool
{
	return fmod($number, 1) != 0.0;
}
function conquer(array $values, bool $recursive = true): mixed
{
	if (empty($values)) {
		return null;
	}
	$allNumerics = true;
	foreach ($values as $value) {
		if (!is_numeric($value)) {
			$allNumerics = false;
			break;
		}
	}
	if ($allNumerics) {
		$s = join('', $values);
		$n = (float) $s;
		return has_non_zero_decimal($n) ? $n : (int) $s;
	}
	$firstValue = $values[0];
	if (is_bool($firstValue)) {
		return in_array(true, $values, true);
	}
	if (is_string($firstValue)) {
		return implode('', $values);
	}
	if (is_array($firstValue)) {
		$merged = [];
		foreach ($values as $value) {
			$merged = array_merge($merged, (array) $value);
		}
		if ($recursive) {
			return conquer($merged);
		}
		return $merged;
	}
	if (is_object($firstValue)) {
		$result = new stdClass();
		foreach ($values as $object) {
			$array = (array) $object;
			if (is_array($array)) {
				foreach ($array as $prop => $val) {
					$result->$prop = $val;
				}
			}
		}
		return $result;
	}
	$keys = array_keys($values);
	if (is_string($keys[0])) {
		if ($recursive) {
			return string((object) $values);
		}
		return (object) $values;
	}
	$array = [];
	foreach ($values as $value) {
		$array[] = string($value);
	}
	return join('', $array);
}
function search(mixed $haystack, mixed $needle, int $offset = 0, ?string $encoding = null): int|string|false
{
	if (empty($haystack) || empty($needle)) {
		return false;
	}
	if (is_numeric($haystack)) {
		$haystack = string($haystack);
	}
	if (is_string($haystack)) {
		return mb_strpos($haystack, string($needle), $offset, $encoding);
	}
	if (is_object($haystack)) {
		$haystack = (array) $haystack;
	}
	if (is_array($haystack)) {
		return array_search($needle, $haystack, true);
	}
	if (is_bool($haystack)) {
		return $haystack === $needle ? 0 : false;
	}
	return false;
}
function pad(mixed $value, int $length, mixed $padValue = ' ', int $padType = STR_PAD_RIGHT)
{
	if (empty($padValue) && $padValue !== 0 && $padValue !== '0') {
		return $value;
	}
	if (is_object($value) || is_bool($value)) {
		return $value;
	}
	$arr = is_array($value) ? $value : str_split(string($value));
	$len = count($arr);
	if ($len >= $length) {
		return $value;
	}
	$padLength = $length - $len;
	$padArray = array_fill(0, $padLength, $padValue);
	if ($padType === STR_PAD_LEFT) {
		$arr = array_merge($padArray, $arr);
	} else {
		$arr = array_merge($arr, $padArray);
	}
	return match (true) {
		is_string($value) => implode('', $arr),
		is_numeric($value) => is_float($value + 0) ? floatval(implode('', $arr)) : intval(implode('', $arr)),
		default => $arr
	};
}
function validate_required_fields_of_list(array $array, array $requiredFields): bool
{
	foreach ($array as $item) {
		if (!validate_required_fields_of_item($item, $requiredFields)) {
			return false;
		}
	}
	return true;
}
function validate_required_fields_of_item($object, array $required_fields): bool
{
	if (!is_object($object)) {
		return false; 
	}
	foreach ($required_fields as $field => $validator) {
		if (is_numeric($field) && is_string($validator)) {
			$field = $validator;
			$validator = fn ($v) => true; 
		}
		if (is_callable($validator)) {
			if (!property_exists($object, $field) || !$validator($object->$field)) {
				return false; 
			}
		}
	}
	return true;
}
function random_hash($algorithm = 'md5'): ?string
{
	try {
		$bytes = random_bytes(16); 
		return hash($algorithm, $bytes);
	} catch (Exception $e) {
		error_log("Error generating random hash: " . $e->getMessage());
		return null; 
	}
}
function secure_random_hash(): ?string
{
	try {
		$bytes = random_bytes(32); 
		return bin2hex($bytes); 
	} catch (Exception $e) {
		error_log("Error generating secure random hash: " . $e->getMessage());
		return null;
	}
}
function trimer(string $str, ?string $chars = null): string
{
	if ($chars !== null) {
		$chars = preg_quote($chars, '/');
		$pattern = "^[$chars]+|[$chars]+$";
	} else {
		$pattern = '^\s+|\s+$';
	}
	return string(preg_replace("/$pattern/u", "", $str));
}
function clean_email_address(string $emailAddress): string
{
	$emailAddress = lower(trim($emailAddress));
	$emailAddress = string(preg_replace('/\s+/', '', $emailAddress));
	if (strpos($emailAddress, '@gmail.com') !== false) {
		list($localPart, $domain) = explode('@', $emailAddress);
		$localPart = str_replace('.', '', $localPart);  
		$emailAddress = $localPart . '@' . $domain;
	}
	return $emailAddress;
}
function json_decode_or_default($jsonCode, $default = null)
{
	$json = string($jsonCode);
	if (empty($json)) {
		return $default;
	}
	return json_decode($json);
}
