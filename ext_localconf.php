<?php

declare(strict_types=1);

use Mfd\AdaptiveVideo\Hooks\DataHandlerHook;
use Mfd\AdaptiveVideo\Resource\Rendering\AdaptiveVideoRenderer;
use TYPO3\CMS\Core\Resource\Rendering\RendererRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

// TYPO3 v13 and v14 register renderers here; v15 switches to #[AsFileRenderer]
GeneralUtility::makeInstance(RendererRegistry::class)->registerRendererClass(AdaptiveVideoRenderer::class);

// Queues videos right after saving a file reference; the scan remains the main way
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['adaptive_video'] = DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['adaptive_video'] = DataHandlerHook::class;
