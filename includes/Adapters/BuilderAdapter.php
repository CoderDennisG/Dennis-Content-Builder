<?php
declare(strict_types=1);

namespace DCB\Adapters;

use WP_Post;

/**
 * Contract between the builder-agnostic core and a specific page builder.
 * See docs/ARCHITECTURE.md — the AI only ever sees the neutral model.
 */
interface BuilderAdapter {

	/** Can this adapter handle this post? */
	public function supports( WP_Post $post ): bool;

	/** post_content -> neutral element tree (raw passthrough for unknowns). */
	public function parse( WP_Post $post ): array;

	/** Neutral element tree -> post_content. */
	public function serialize( array $elements ): string;

	/** Human-readable adapter name. */
	public function label(): string;
}
