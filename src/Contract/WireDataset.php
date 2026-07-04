<?php
/**
 * Terraviz wire Dataset (GET /api/v1/datasets/:id, and each catalog entry).
 *
 * GENERATED FILE — do not edit by hand.
 * Source schema: https://terraviz.zyra-project.org/schema/v1/dataset.schema.json
 * Regenerate with: php bin/generate-contracts.php
 *
 * @package Terraviz
 */

declare( strict_types = 1 );

namespace Terraviz\Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terraviz wire Dataset (GET /api/v1/datasets/:id, and each catalog entry).
 */
final class WireDataset extends Wire {

	/**
	 * Every property declared by the schema.
	 *
	 * @var array<int,string>
	 */
	public const PROPERTIES = array( 'id', 'slug', 'title', 'format', 'dataLink', 'organization', 'abstractTxt', 'thumbnailLink', 'legendLink', 'closedCaptionLink', 'colorTableLink', 'websiteLink', 'startTime', 'endTime', 'period', 'weight', 'isHidden', 'runTourOnLoad', 'tags', 'enriched', 'originNode', 'originNodeUrl', 'originDisplayName', 'visibility', 'schemaVersion', 'licenseSpdx', 'licenseUrl', 'licenseStatement', 'attributionText', 'rightsHolder', 'doi', 'citationText', 'createdAt', 'updatedAt', 'publishedAt', 'legacyId', 'probingInfo', 'boundingBox', 'celestialBody', 'radiusMi', 'lonOrigin', 'isFlippedInY', 'tourJsonUrl', 'frames' );

	/**
	 * Properties the schema marks required.
	 *
	 * @var array<int,string>
	 */
	public const REQUIRED = array( 'id', 'slug', 'title', 'format', 'dataLink', 'originNode', 'originNodeUrl', 'originDisplayName', 'visibility', 'schemaVersion', 'createdAt', 'updatedAt' );

	/**
	 * @return string|null
	 */
	public function id() {
		return $this->scalar( 'id' );
	}

	/**
	 * @return string|null
	 */
	public function slug() {
		return $this->scalar( 'slug' );
	}

	/**
	 * @return string|null
	 */
	public function title() {
		return $this->scalar( 'title' );
	}

	/**
	 * @return string|null
	 */
	public function format() {
		return $this->scalar( 'format' );
	}

	/**
	 * @return string|null
	 */
	public function dataLink() {
		return $this->scalar( 'dataLink' );
	}

	/**
	 * @return string|null
	 */
	public function organization() {
		return $this->scalar( 'organization' );
	}

	/**
	 * @return string|null
	 */
	public function abstractTxt() {
		return $this->scalar( 'abstractTxt' );
	}

	/**
	 * @return string|null
	 */
	public function thumbnailLink() {
		return $this->scalar( 'thumbnailLink' );
	}

	/**
	 * @return string|null
	 */
	public function legendLink() {
		return $this->scalar( 'legendLink' );
	}

	/**
	 * @return string|null
	 */
	public function closedCaptionLink() {
		return $this->scalar( 'closedCaptionLink' );
	}

	/**
	 * @return string|null
	 */
	public function colorTableLink() {
		return $this->scalar( 'colorTableLink' );
	}

	/**
	 * @return string|null
	 */
	public function websiteLink() {
		return $this->scalar( 'websiteLink' );
	}

	/**
	 * @return string|null
	 */
	public function startTime() {
		return $this->scalar( 'startTime' );
	}

	/**
	 * @return string|null
	 */
	public function endTime() {
		return $this->scalar( 'endTime' );
	}

	/**
	 * @return string|null
	 */
	public function period() {
		return $this->scalar( 'period' );
	}

	/**
	 * @return float|null
	 */
	public function weight() {
		return $this->number( 'weight' );
	}

	/**
	 * @return bool|null
	 */
	public function isHidden() {
		return $this->boolean( 'isHidden' );
	}

	/**
	 * @return string|null
	 */
	public function runTourOnLoad() {
		return $this->scalar( 'runTourOnLoad' );
	}

	/**
	 * @return array<int,mixed>
	 */
	public function tags() {
		return $this->list( 'tags' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function enriched() {
		return $this->object( 'enriched' );
	}

	/**
	 * @return string|null
	 */
	public function originNode() {
		return $this->scalar( 'originNode' );
	}

	/**
	 * @return string|null
	 */
	public function originNodeUrl() {
		return $this->scalar( 'originNodeUrl' );
	}

	/**
	 * @return string|null
	 */
	public function originDisplayName() {
		return $this->scalar( 'originDisplayName' );
	}

	/**
	 * @return string|null
	 */
	public function visibility() {
		return $this->scalar( 'visibility' );
	}

	/**
	 * @return float|null
	 */
	public function schemaVersion() {
		return $this->number( 'schemaVersion' );
	}

	/**
	 * @return string|null
	 */
	public function licenseSpdx() {
		return $this->scalar( 'licenseSpdx' );
	}

	/**
	 * @return string|null
	 */
	public function licenseUrl() {
		return $this->scalar( 'licenseUrl' );
	}

	/**
	 * @return string|null
	 */
	public function licenseStatement() {
		return $this->scalar( 'licenseStatement' );
	}

	/**
	 * @return string|null
	 */
	public function attributionText() {
		return $this->scalar( 'attributionText' );
	}

	/**
	 * @return string|null
	 */
	public function rightsHolder() {
		return $this->scalar( 'rightsHolder' );
	}

	/**
	 * @return string|null
	 */
	public function doi() {
		return $this->scalar( 'doi' );
	}

	/**
	 * @return string|null
	 */
	public function citationText() {
		return $this->scalar( 'citationText' );
	}

	/**
	 * @return string|null
	 */
	public function createdAt() {
		return $this->scalar( 'createdAt' );
	}

	/**
	 * @return string|null
	 */
	public function updatedAt() {
		return $this->scalar( 'updatedAt' );
	}

	/**
	 * @return string|null
	 */
	public function publishedAt() {
		return $this->scalar( 'publishedAt' );
	}

	/**
	 * @return string|null
	 */
	public function legacyId() {
		return $this->scalar( 'legacyId' );
	}

	/**
	 * @return mixed
	 */
	public function probingInfo() {
		return $this->get( 'probingInfo' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function boundingBox() {
		return $this->object( 'boundingBox' );
	}

	/**
	 * @return string|null
	 */
	public function celestialBody() {
		return $this->scalar( 'celestialBody' );
	}

	/**
	 * @return float|null
	 */
	public function radiusMi() {
		return $this->number( 'radiusMi' );
	}

	/**
	 * @return float|null
	 */
	public function lonOrigin() {
		return $this->number( 'lonOrigin' );
	}

	/**
	 * @return bool|null
	 */
	public function isFlippedInY() {
		return $this->boolean( 'isFlippedInY' );
	}

	/**
	 * @return string|null
	 */
	public function tourJsonUrl() {
		return $this->scalar( 'tourJsonUrl' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function frames() {
		return $this->object( 'frames' );
	}

}
