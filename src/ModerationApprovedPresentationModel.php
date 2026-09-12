<?php
/**
 * 「审核结果」通知在 wiki 内的呈现（Echo 的 presentation model）。
 *
 * Echo 要求每个事件类型都有一个 presentation model，否则**在 wiki 里渲染通知时会抛异常** ——
 * 即使我们只关心 OTTOhub 私信通道，也必须提供它。
 *
 * 基类 `EchoEventPresentationModel` 在全局命名空间（Echo 用 class_alias 暴露）。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use EchoEventPresentationModel;
use MediaWiki\Message\Message;

class ModerationApprovedPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		// OOUI 自带图标，含义就是"页面已核对"
		return 'articleCheck';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		$title = $this->event->getTitle();
		return $this->msg( 'notification-header-' . $this->type )
			->params( $title !== null ? $title->getPrefixedText() : '' );
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		return false;
	}

	/** @inheritDoc */
	public function getPrimaryLink(): array {
		$title = $this->event->getTitle();
		return [
			'url' => $title !== null ? $title->getFullURL() : '',
			'label' => $this->msg( 'notification-link-label-' . $this->type ),
		];
	}
}
