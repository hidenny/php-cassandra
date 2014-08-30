<?php 
namespace Cassandra\Response;
use Cassandra\Enum\DataTypeEnum;

class Result extends DataStream{
	const VOID = 0x0001;
	const ROWS = 0x0002;
	const SET_KEYSPACE = 0x0003;
	const PREPARED = 0x0004;
	const SCHEMA_CHANGE = 0x0005;
	
	const ROWS_FLAG_GLOBAL_TABLES_SPEC = 0x0001;
	const ROWS_FLAG_HAS_MORE_PAGES = 0x0002;
	const ROWS_FLAG_NO_METADATA = 0x0004;
	
	/**
	 * build a data stream first and read by type
	 *
	 * @param array $type
	 * @return mixed
	 */
	public function readByTypeFromStream(array $type){
		$length = unpack('N', substr($this->data, $this->offset, 4))[1];
		$this->offset += 4;
		
		if ($this->length < $this->offset + $length)
			return null;
		
		// do not use $this->read() for performance
		$data = substr($this->data, $this->offset, $length);
		$this->offset += $length;
		
		switch ($type['type']) {
			case DataTypeEnum::ASCII:
			case DataTypeEnum::VARCHAR:
			case DataTypeEnum::TEXT:
				return $data;
			case DataTypeEnum::BIGINT:
			case DataTypeEnum::COUNTER:
			case DataTypeEnum::VARINT:
				$unpacked = unpack('N2', $data);
				return $unpacked[1] << 32 | $unpacked[2];
			case DataTypeEnum::CUSTOM:
			case DataTypeEnum::BLOB:
				$length = unpack('N', substr($data, 0, 4))[1];
				if ($length == 4294967295 || $length + 4 > strlen($data))
					return null;
				return substr($data, 4, $length);
			case DataTypeEnum::BOOLEAN:
				return (bool) unpack('C', $data)[1];
			case DataTypeEnum::DECIMAL:
				$unpacked = unpack('N3', $data);
				$value = $unpacked[2] << 32 | $unpacked[3];
				$len = strlen($value);
				return substr($value, 0, $len - $unpacked[1]) . '.' . substr($value, $len - $unpacked[1]);
			case DataTypeEnum::DOUBLE:
				return unpack('d', strrev($data))[1];
			case DataTypeEnum::FLOAT:
				return unpack('f', strrev($data))[1];
			case DataTypeEnum::INT:
				return unpack('N', $data)[1];
			case DataTypeEnum::TIMESTAMP:
				$unpacked = unpack('N2', $data);
				return round($unpacked[1] * 4294967.296 + ($unpacked[2] / 1000));
			case DataTypeEnum::UUID:
			case DataTypeEnum::TIMEUUID:
				$uuid = '';
				for ($i = 0; $i < 16; ++$i) {
					if ($i == 4 || $i == 6 || $i == 8 || $i == 10) {
						$uuid .= '-';
					}
					$uuid .= str_pad(dechex(ord($data{$i})), 2, '0', STR_PAD_LEFT);
				}
				return $uuid;
			case DataTypeEnum::INET:
				return inet_ntop($data);
			case DataTypeEnum::COLLECTION_LIST:
			case DataTypeEnum::COLLECTION_SET:
				$dataStream = new DataStream($data);
				return $dataStream->readList($type['value']);
			case DataTypeEnum::COLLECTION_MAP:
				$dataStream = new DataStream($data);
				return $dataStream->readMap($type['key'], $type['value']);
			default:
				trigger_error('Unknown type ' . var_export($type, true));
				return null;
		}
	}
	
	/**
	 * @return Rows|string|array|null
	 */
	public function getData() {
		$kind = parent::readInt();
		switch($kind) {
			case self::VOID:
				return null;
	
			case self::ROWS:
				$columns = $this->getColumns();
				$rowCount = parent::readInt();
				$rows = new \SplFixedArray($rowCount);
				$rows->columns = $columns;
	
				for ($i = 0; $i < $rowCount; ++$i) {
					$row = new ArrayObject();
						
					foreach ($columns as $column)
						$row[$column['name']] = self::readByTypeFromStream($column['type']);
						
					$rows[$i] = $row;
				}
	
				return $rows;
	
			case self::SET_KEYSPACE:
				return parent::readString();
	
			case self::PREPARED:
				return [
					'id' => parent::readString(),
					'columns' => $this->getColumns()
				];
	
			case self::SCHEMA_CHANGE:
				return [
					'change' => parent::readString(),
					'keyspace' => parent::readString(),
					'table' => parent::readString()
				];
		}
	
		return null;
	}
	
	/**
	 * @return mixed
	 */
	protected function readType(){
		$data = [
			'type' => unpack('n', $this->read(2))[1]
		];
		switch ($data['type']) {
			case DataTypeEnum::CUSTOM:
				$data['name'] = $this->read(unpack('n', $this->read(2))[1]);
				break;
			case DataTypeEnum::COLLECTION_LIST:
			case DataTypeEnum::COLLECTION_SET:
				$data['value'] = self::readType();
				break;
			case DataTypeEnum::COLLECTION_MAP:
				$data['key'] = self::readType();
				$data['value'] = self::readType();
				break;
			default:
		}
		return $data;
	}
	
	/**
	 * Return metadata
	 * @return array
	 */
	private function getColumns() {
		$unpacked = unpack('N2', $this->read(8));
		$flags = $unpacked[1];
		$columnCount = $unpacked[2];
		
		if ($flags & self::ROWS_FLAG_GLOBAL_TABLES_SPEC) {
			$keyspace = $this->read(unpack('n', $this->read(2))[1]);
			$tableName = $this->read(unpack('n', $this->read(2))[1]);
			
			$columns = [];
			for ($i = 0; $i < $columnCount; ++$i) {
				$columnData = [
					'keyspace' => $keyspace,
					'tableName' => $tableName,
					'name' => $this->read(unpack('n', $this->read(2))[1]),
					'type' => self::readType()
				];
				$columns[] = $columnData;
			}
		}
		else {
			$columns = [];
			for ($i = 0; $i < $columnCount; ++$i) {
				$columnData = [
					'keyspace' => $this->read(unpack('n', $this->read(2))[1]),
					'tableName' => $this->read(unpack('n', $this->read(2))[1]),
					'name' => $this->read(unpack('n', $this->read(2))[1]),
					'type' => self::readType()
				];
				$columns[] = $columnData;
			}
		}
	
		return $columns;
	}
	
	/**
	 *
	 * @param int $kind
	 * @throws Exception
	 * @return NULL
	 */
	protected function _throwException($kind){
		switch($kind){
			case self::VOID:
				throw new Exception('Unexpected Response: VOID');
	
			case self::ROWS:
				throw new Exception('Unexpected Response: ROWS');
	
			case self::SET_KEYSPACE:
				throw new Exception('Unexpected Response: SET_KEYSPACE ' . parent::readString());
	
			case self::PREPARED:
				throw new Exception('Unexpected Response: PREPARED id:' . parent::readString() . ' columns:' . $this->getColumns());
	
			case self::SCHEMA_CHANGE:
				throw new Exception('Unexpected Response: SCHEMA_CHANGE change:' . parent::readString() . ' keyspace:' . parent::readString() . ' table:' . parent::readString());
	
			default:
				throw new Exception('Unexpected Response: ' . $kind);
		}
	}
	
	/**
	 *
	 * @throws Exception
	 * @return \SplFixedArray
	 */
	public function fetchAll($rowClass = 'ArrayObject'){
		$kind = parent::readInt();
	
		if ($kind !== self::ROWS){
			$this->_throwException($kind);
		}
	
		$columns = $this->getColumns();
		$rowCount = parent::readInt();
		$rows = new \SplFixedArray($rowCount);
		$rows->columns = $columns;
	
		for ($i = 0; $i < $rowCount; ++$i) {
			$row = new $rowClass();
	
			foreach ($columns as $column)
				$row[$column['name']] = self::readByTypeFromStream($column['type']);
				
			$rows[$i] = $row;
		}
	
		return $rows;
	}
	
	/**
	 *
	 * @throws Exception
	 * @return \SplFixedArray
	 */
	public function fetchCol($index = 0){
		$kind = parent::readInt();
	
		if ($kind !== self::ROWS){
			$this->_throwException($kind);
		}
	
		$columns = $this->getColumns();
		$columnCount = count($columns);
		$rowCount = parent::readInt();
	
		$array = new \SplFixedArray($rowCount);
	
		for($i = 0; $i < $rowCount; ++$i){
			for($j = 0; $j < $columnCount; ++$j){
				$value = self::readByTypeFromStream($columns[$j]['type']);
	
				if ($j == $index)
					$array[$i] = $row;
			}
		}
	
		return $array;
	}
	
	/**
	 *
	 * @throws Exception
	 * @return \ArrayObject
	 */
	public function fetchRow($rowClass = 'ArrayObject'){
		$kind = parent::readInt();
	
		if ($kind !== self::ROWS){
			$this->_throwException($kind);
		}
	
		$columns = $this->getColumns();
		$rowCount = parent::readInt();
	
		if ($rowCount === 0)
			return null;
	
		$row = new $rowClass();
		foreach ($columns as $column)
			$row[$column['name']] = self::readByTypeFromStream($column['type']);
	
		return $row;
	}
	
	/**
	 *
	 * @throws Exception
	 * @return mixed
	 */
	public function fetchOne(){
		$kind = parent::readInt();
	
		if ($kind !== self::ROWS){
			$this->_throwException($kind);
		}
	
		$columns = $this->getColumns();
		$rowCount = parent::readInt();
	
		if ($rowCount === 0)
			return null;
	
		foreach ($columns as $column)
			return self::readByTypeFromStream($column['type']);
	
		return null;
	}
}