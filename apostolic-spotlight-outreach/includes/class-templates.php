<?php
/**
 * Editable email templates (stored in wp_aso_templates). Defaults are seeded once
 * and are fully editable afterwards – nothing here is sent automatically except
 * templates explicitly placed in a campaign sequence.
 *
 * @package ApostolicSpotlight\Outreach
 */

namespace ApostolicSpotlight\Outreach;

defined( 'ABSPATH' ) || exit;

final class Templates {

	public static function categories() {
		return array(
			'invitation' => __( 'Spotlight Invitation', 'apostolic-spotlight-outreach' ),
			'follow_up'  => __( 'Spotlight Follow-Up', 'apostolic-spotlight-outreach' ),
			'website'    => __( 'Website Follow-Up (manual only)', 'apostolic-spotlight-outreach' ),
			'grow'       => __( 'Apostolic Grow Follow-Up (manual only)', 'apostolic-spotlight-outreach' ),
			'general'    => __( 'General', 'apostolic-spotlight-outreach' ),
		);
	}

	/**
	 * Categories that may be used in automated campaign sequences.
	 * Website / Grow templates are intentionally excluded: those are manual only.
	 */
	public static function sequence_categories() {
		return array( 'invitation', 'follow_up', 'general' );
	}

	private static function table() {
		return Installer::table( 'templates' );
	}

	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function find_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s ORDER BY id ASC LIMIT 1', $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function all( $categories = array() ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table();
		if ( $categories ) {
			$in  = implode( ',', array_fill( 0, count( $categories ), '%s' ) );
			$sql = $wpdb->prepare( $sql . " WHERE category IN ($in)", $categories ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $wpdb->get_results( $sql . ' ORDER BY FIELD(category,\'invitation\',\'follow_up\',\'website\',\'grow\',\'general\'), name ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'name'       => sanitize_text_field( $data['name'] ),
			'category'   => array_key_exists( $data['category'], self::categories() ) ? $data['category'] : 'general',
			'subject'    => sanitize_text_field( $data['subject'] ),
			'body'       => sanitize_textarea_field( $data['body'] ),
			'updated_at' => Util::now_db(),
		);
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
			return (int) $id;
		}
		$row['slug']       = isset( $data['slug'] ) ? sanitize_key( $data['slug'] ) : '';
		$row['created_at'] = Util::now_db();
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		$steps = Installer::table( 'steps' );
		$used  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$steps} WHERE template_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $used ) {
			return new \WP_Error( 'aso_template_in_use', __( 'This template is used by a campaign sequence and cannot be deleted.', 'apostolic-spotlight-outreach' ) );
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Seed default templates once (by slug). Never overwrites edits.
	 */
	public static function seed_defaults() {
		foreach ( self::default_templates() as $slug => $tpl ) {
			if ( self::find_by_slug( $slug ) ) {
				continue;
			}
			$tpl['slug'] = $slug;
			self::save( $tpl );
		}
	}

	public static function default_templates() {
		return array(
			'spotlight_invitation' => array(
				'name'     => 'Spotlight Invitation',
				'category' => 'invitation',
				'subject'  => 'We’d love to feature {{church_name}}',
				'body'     => "Pastor {{pastor_name}},\n\nMy name is {{sender_name}}, and I’m the founder of Apostolic Spotlight.\n\n{{church_name}} is already included in our Apostolic church directory as a basic listing, and I’d love to upgrade that into a full Spotlight feature at no cost.\n\nThe process is simple: we schedule one short 15-minute call where I learn more about your church, its history, leadership, ministries, and what God is doing in your community.\n\nFrom that conversation, I’ll create a full Apostolic Spotlight feature for your church and share it through Apostolic Spotlight.\n\nThere is no cost and no obligation. My goal is simply to help more people discover great Apostolic churches and tell the stories of what God is doing in them.\n\nIf you’re interested, you can choose a time that works for you here:\n{{booking_url}}\n\nThanks,\n{{sender_name}}\nApostolic Spotlight",
			),
			'spotlight_followup_1' => array(
				'name'     => 'Spotlight Follow-Up 1',
				'category' => 'follow_up',
				'subject'  => 'Following up – a Spotlight for {{church_name}}',
				'body'     => "Pastor {{pastor_name}},\n\nI wanted to follow up on my note from a few days ago. I’d still love to feature {{church_name}} with a free Apostolic Spotlight.\n\nIt’s just one short 15-minute conversation, and I take care of everything else. If this is a good season for it, you can pick a time here:\n{{booking_url}}\n\nThanks,\n{{sender_name}}\nApostolic Spotlight",
			),
			'spotlight_followup_2' => array(
				'name'     => 'Spotlight Follow-Up 2',
				'category' => 'follow_up',
				'subject'  => 'One last note about {{church_name}}',
				'body'     => "Pastor {{pastor_name}},\n\nI know your schedule is full, so this will be my last note about this. If you’d ever like {{church_name}} to have a free Spotlight feature, the invitation stays open – you can choose a time whenever it works for you:\n{{booking_url}}\n\nYou can see your church’s current listing here:\n{{spotlight_url}}\n\nGod bless,\n{{sender_name}}\nApostolic Spotlight",
			),
			'website_followup'     => array(
				'name'     => 'Website Follow-Up (manual)',
				'category' => 'website',
				'subject'  => 'A thought about {{church_name}}’s website',
				'body'     => "Pastor {{pastor_name}},\n\nI hope you liked how your Spotlight turned out.\n\nWhile I was putting everything together, I spent some time looking through your church’s website as well.\n\nI noticed a few areas where I think it could better represent the church and make things easier for someone considering visiting.\n\nI’ve started helping a limited number of Apostolic churches with their websites differently than a traditional web company.\n\nI don’t have a fixed design fee. I simply ask the church to pray about what they feel led to give, and 25% of whatever is given goes toward a designated Apostolic missions project.\n\nIf that’s something you’d be interested in, I’d be happy to show you what I would recommend for your church.\n\nThanks,\n{{sender_first_name}}",
			),
			'grow_followup'        => array(
				'name'     => 'Apostolic Grow Follow-Up (manual)',
				'category' => 'grow',
				'subject'  => 'Following up with first-time guests at {{church_name}}',
				'body'     => "Pastor {{pastor_name}},\n\nI hope you enjoyed how {{church_name}}’s Spotlight turned out.\n\nOne thing that came up as I was putting it together was how churches follow up with first-time guests. That’s something I’ve been working on through Apostolic Grow – a simple way to make sure every visitor gets a personal, timely follow-up so no one slips through the cracks.\n\nNo pressure at all, but if that’s something you’d like to see, I’d be glad to walk you through it.\n\nThanks,\n{{sender_first_name}}",
			),
		);
	}
}
