<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Ingo Renner <ingo@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * A service to extract meta data from files using Apache Tika
 *
 * @author	Ingo Renner <ingo@typo3.org>
 * @package TYPO3
 * @subpackage tika
 */
class tx_tika_MetaDataExtractionService extends t3lib_svbase {

	public $prefixId      = 'tx_tika_MetaDataExtractionService';
	public $scriptRelPath = 'classes/class.tx_tika_metadataextractionservice.php';
	public $extKey        = 'tika';

	protected $tikaConfiguration;

	/**
	 * Checks whether the service is available, reads the extension's
	 * configuration.
	 *
	 * @return	boolean	True if the service is available, false otherwise.
	 */
	public function init() {
		$available = parent::init();

		$this->tikaConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['tika']);

		if (!is_file($this->tikaConfiguration['tikaPath'])) {
			throw new Exception(
				'Invalid path or filename for tika application jar.',
				1266864929
			);
		}

		return $available;
	}

	/**
	 * Extracs meta data from a file using Apache Tika
	 *
	 * @param	string		Content which should be processed.
	 * @param	string		Content type
	 * @param	array		Configuration array
	 * @return	boolean
	 */
	public function process($content = '', $type = '', $configuration = array()) {
		$this->out =  array();
		$this->out['fields'] = array();

		if ($inputFile = $this->getInputFile()) {
			$tikaCommand = t3lib_exec::getCommand('java')
				. ' -Dfile.encoding=UTF8'
				. ' -jar ' . escapeshellarg($this->tikaConfiguration['tikaPath'])
				. ' -m ' . escapeshellarg($inputFile);

			$shellOutput = array();
			exec($tikaCommand, $shellOutput);

			$metaData  = $this->shellOutputToArray($shellOutput);
			$cleanData = $this->normalizeMetaData($metaData);
			$this->out = $cleanData;

				// DAMnizing ;)
			$this->damnizeData($cleanData);
		} else {
			$this->errorPush(T3_ERR_SV_NO_INPUT, 'No or empty input.');
		}

		return $this->getLastError();
	}

	/**
	 * Takes shell output from exec() and turns it into an array of key => value
	 * meta data pairs.
	 *
	 * @param	array	An array containing shell output from exec() with one line per entry
	 * @return	array	Array of key => value pairs of meta data
	 */
	protected function shellOutputToArray(array $shellOutputMetaData) {
		$metaData = array();

		foreach ($shellOutputMetaData as $line) {
			list($dataName, $dataValue) = explode(':', $line, 2);
			$metaData[$dataName] = trim($dataValue);
		}

		return $metaData;
	}

	/**
	 * Normalizes the names / keys of the meta data found.
	 *
	 * @param	array	An array of raw meta data from a file
	 * @return	array	An array with cleaned meta data keys
	 */
	protected function normalizeMetaData(array $metaData) {
		$metaDataCleaned = array();

		foreach ($metaData as $key => $value) {
				// still add the value
			$metaDataCleaned[$key] = $value;

				// clean / add values under alternative names
			switch($key) {
				case 'height':
					$height = $value;
					unset($metaDataCleaned[$key]);
					$metaDataCleaned['Height'] = $height;
					break;
				case 'width':
					$width = $value;
					unset($metaDataCleaned[$key]);
					$metaDataCleaned['Width'] = $width;
					break;
				case 'Image Height':
					list($height) = explode(' ', $value, 2);
					$metaDataCleaned['Height'] = $height;
					break;
				case 'Image Width':
					list($width) = explode(' ', $value, 2);
					$metaDataCleaned['Width']  = $width;
					break;
			}
		}

		return $metaDataCleaned;
	}

	/**
	 * Turns the data into a format / fills the fields so that DAM can use the
	 * meta data.
	 *
	 * @param	array	An array with cleaned meta data keys
	 */
	protected function damnizeData(array $metaData) {
		$this->out['fields']['meta'] = $metaData;

		$this->out['fields']['vpixels']      = $metaData['Width'];
		$this->out['fields']['hpixels']      = $metaData['Height'];

			// JPEG comment
		if (!empty($metaData['Jpeg Comment'])) {
			$this->out['fields']['description'] = $metaData['Jpeg Comment'];
		}

			// EXIF data
		if (isset($metaData['Color Space']) && $metaData['Color Space'] != 'Undefined') {
			$this->out['fields']['color_space'] = $metaData['Color Space'];
		}

		$copyright = array();
		if(!empty($metaData['Copyright'])) {
			$copyright[] = $metaData['Copyright'];
		}
		if(!empty($metaData['Copyright Notice'])) {
			$copyright[] = $metaData['Copyright Notice'];
		}
		if (!empty($copyright)) {
			$this->out['fields']['copyright'] = implode("\n", $copyright);
		}

		if(isset($metaData['Date/Time Original'])) {
			$this->out['fields']['date_cr'] = $this->exifDateToTimestamp($metaData['Date/Time Original']);
		}

		if (isset($metaData['Keywords'])) {
			$this->out['fields']['keywords'] = implode(', ', explode(' ', $metaData['Keywords']));
		}

		if(isset($metaData['Model'])) {
			$this->out['fields']['file_creator'] = $metaData['Model'];
		}

		if (isset($metaData['X Resolution'])) {
			list($horizontalResolution) = explode(' ', $metaData['X Resolution'], 2);
			$this->out['fields']['hres'] = $horizontalResolution;
		}
		if (isset($metaData['Y Resolution'])) {
			list($verticalResolution) = explode(' ', $metaData['Y Resolution'], 2);
			$this->out['fields']['vres'] = $verticalResolution;
		}
	}

	/**
	 * Converts a date string into timestamp
	 * exiftags: 2002:09:07 15:29:52
	 *
	 * @param	string	An exif date string
	 * @return	integer	Unix timestamp
	 */
	protected function exifDateToTimestamp($date)	{
		if (is_string($date)) {
			if (($timestamp = strtotime($date)) === -1) {
				$date = 0;
			} else {
				$date = $timestamp;
			}
		}

		return $date;
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/tika/classes/class.tx_tika_metadataextractionservice.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/tika/classes/class.tx_tika_metadataextractionservice.php']);
}

?>