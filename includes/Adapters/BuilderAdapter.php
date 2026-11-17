<?php
declare(strict_types=1);

namespace Denise\Adapters;

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

	/**
	 * Post meta to set when first inserting a post for this builder.
	 * Gutenberg needs none; Elementor needs its edit-mode flags. Returned
	 * as a wp_insert_post() 'meta_input' map.
	 *
	 * @return array<string,mixed>
	 */
	public function insert_meta( string $post_type ): array;

	/**
	 * Persist an element tree onto an existing post in this builder's own
	 * storage. Gutenberg writes post_content; Elementor writes _elementor_data
	 * meta. Throws on failure so the tool layer reports it to the model.
	 */
	public function persist( int $post_id, array $elements ): void;

	/** Human-readable adapter name. */
	public function label(): string;
}
