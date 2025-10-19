<!-- @format -->

# YT Post Publish Scheduler

A single-file WordPress plugin that automatically schedules posts to unpublish and republish at specified dates. Perfect for seasonal content, time-limited offers, and recurring announcements. Built following WordPress Coding Standards (WPCS).

## Features

- **Automatic Scheduling**: Set unpublish and republish dates for any post type
- **WordPress Cron**: Uses built-in WP-Cron for reliable scheduling
- **Activity Logging**: Track all scheduling actions in a dedicated database table
- **Email Notifications**: Get notified when posts are unpublished/republished
- **Flexible Post Types**: Enable scheduling for posts, pages, and custom post types
- **Admin Columns**: See schedule information directly in the post list
- **Meta Box UI**: Easy-to-use datetime picker in the post editor
- **Settings Page**: Comprehensive admin settings with scheduled posts overview
- **WPCS Compliant**: Follows WordPress Coding Standards
- **Secure**: Proper sanitization, validation, escaping, and nonce verification
- **Translation Ready**: Full i18n/l10n support
- **Single File**: All code in one ~1055-line PHP file

## Installation

1. Download `class-yt-post-publish-scheduler.php`
2. Upload to `/wp-content/plugins/` directory
3. Activate through the 'Plugins' menu in WordPress
4. Navigate to **Settings → Publish Scheduler** to configure

## Quick Start

### Basic Usage

1. **Edit any post** (or enabled post type)
2. Find the **"Publish Schedule"** meta box in the sidebar
3. Set an **Unpublish Date** (when the post should be hidden)
4. Optionally set a **Republish Date** (when it should go live again)
5. **Save/Update** the post

The plugin will automatically handle the rest using WordPress cron!

### Settings Configuration

Navigate to **Settings → Publish Scheduler** to configure:

- **Enabled Post Types**: Choose which post types support scheduling
- **Unpublish Status**: Select status for unpublished posts (draft, pending, private)
- **Email Notifications**: Enable/disable and set notification recipient
- **Activity Logging**: Track all scheduling actions
- **Past Dates**: Allow or prevent scheduling in the past (useful for testing)
- **Timezone Display**: Show dates in site timezone or UTC

## Use Cases

### Seasonal Content

```
Blog Post: "Summer Sale 2025"
├─ Unpublish Date: 2025-09-01 00:00
└─ Republish Date: 2026-06-01 00:00
```

Perfect for holiday content, seasonal promotions, or recurring events.

### Time-Limited Offers

```
Landing Page: "Black Friday Deal"
├─ Unpublish Date: 2025-11-30 23:59
└─ Republish Date: (leave empty)
```

Automatically hide expired offers without manual intervention.

### Content Rotation

```
Featured Post: "Monthly Spotlight"
├─ Unpublish Date: 2025-02-01 00:00
└─ Republish Date: 2025-03-01 00:00
```

Rotate featured content or announcements automatically.

## File Structure

```
class-yt-post-publish-scheduler.php    # Main plugin file (all code)
README.md                              # This documentation
```

## Plugin Architecture

### Constants Defined

```php
YT_PPS_VERSION  // Plugin version: 1.0.0
YT_PPS_BASENAME // Plugin basename
YT_PPS_PATH     // Plugin directory path
YT_PPS_URL      // Plugin directory URL
```

### Database Structure

Creates table: `wp_pps_logs`

| Column          | Type         | Description                |
| --------------- | ------------ | -------------------------- |
| id              | bigint(20)   | Primary key                |
| post_id         | bigint(20)   | Post ID                    |
| action          | varchar(20)  | unpublish or republish     |
| old_status      | varchar(20)  | Previous post status       |
| new_status      | varchar(20)  | New post status            |
| scheduled_date  | datetime     | When action was scheduled  |
| executed_date   | datetime     | When action was executed   |
| success         | tinyint(1)   | Success flag               |
| message         | text         | Optional error/info        |

### Post Meta Keys

- `_yt_pps_unpublish_date` - Stores unpublish datetime (MySQL format)
- `_yt_pps_republish_date` - Stores republish datetime (MySQL format)

### Cron Hooks

- `yt_pps_unpublish_post` - Triggered when unpublish date arrives
- `yt_pps_republish_post` - Triggered when republish date arrives

### Main Class Methods

#### Core Methods
- `get_instance()` - Singleton instance retrieval (class-yt-post-publish-scheduler.php:56)
- `__construct()` - Initialize plugin (class-yt-post-publish-scheduler.php:66)
- `init_hooks()` - Register WordPress hooks (class-yt-post-publish-scheduler.php:92)
- `load_textdomain()` - Load translations (class-yt-post-publish-scheduler.php:222)

#### Lifecycle Methods
- `activate()` - Create database table and default settings (class-yt-post-publish-scheduler.php:129)
- `deactivate()` - Clear all scheduled events (class-yt-post-publish-scheduler.php:155)
- `create_log_table()` - Create activity log table (class-yt-post-publish-scheduler.php:165)
- `clear_all_schedules()` - Remove all cron events (class-yt-post-publish-scheduler.php:194)

#### Admin UI Methods
- `add_settings_page()` - Add settings page to menu (class-yt-post-publish-scheduler.php:290)
- `render_settings_page()` - Display settings page (class-yt-post-publish-scheduler.php:343)
- `register_settings()` - Register plugin options (class-yt-post-publish-scheduler.php:303)
- `sanitize_options()` - Sanitize user input (class-yt-post-publish-scheduler.php:320)
- `enqueue_admin_assets()` - Load CSS/JS on admin pages (class-yt-post-publish-scheduler.php:235)

#### Meta Box Methods
- `add_meta_box()` - Register meta box for enabled post types (class-yt-post-publish-scheduler.php:598)
- `render_meta_box()` - Display scheduling UI in post editor (class-yt-post-publish-scheduler.php:616)
- `save_meta_box()` - Save scheduling data and register cron events (class-yt-post-publish-scheduler.php:684)

#### Scheduling Methods
- `unpublish_post()` - Change post status to configured unpublish status (class-yt-post-publish-scheduler.php:754)
- `republish_post()` - Change post status back to publish (class-yt-post-publish-scheduler.php:795)
- `log_action()` - Record action in database log (class-yt-post-publish-scheduler.php:840)
- `send_notification()` - Send email notification (class-yt-post-publish-scheduler.php:869)

#### Display Methods
- `add_admin_columns()` - Add schedule column to post list (class-yt-post-publish-scheduler.php:919)
- `render_admin_columns()` - Display schedule info in column (class-yt-post-publish-scheduler.php:930)
- `render_scheduled_posts_table()` - Show all scheduled posts (class-yt-post-publish-scheduler.php:475)
- `render_activity_log()` - Show recent activity log (class-yt-post-publish-scheduler.php:515)

#### AJAX Handlers
- `ajax_clear_schedule()` - Clear scheduling via AJAX (class-yt-post-publish-scheduler.php:988)
- `ajax_get_scheduled_posts()` - Fetch scheduled posts via AJAX (class-yt-post-publish-scheduler.php:1015)

#### Utility Methods
- `format_date()` - Format datetime for display (class-yt-post-publish-scheduler.php:905)
- `get_scheduled_posts()` - Query all posts with schedules (class-yt-post-publish-scheduler.php:574)

## Admin Interface

### Settings Page

Located at **Settings → Publish Scheduler**

**Configuration Options:**
- Select enabled post types
- Choose unpublish status (draft/pending/private)
- Configure email notifications
- Enable/disable activity logging
- Allow past dates for testing
- Set timezone display preference

**Overview Sections:**
- **Scheduled Posts Table**: View all posts with active schedules
- **Recent Activity Log**: See last 50 scheduling actions (if logging enabled)

### Post Editor Meta Box

Located in the **sidebar** of the post editor (for enabled post types).

**Fields:**
- **Unpublish Date**: datetime-local input
- **Republish Date**: datetime-local input
- **Clear Schedule**: Button to remove scheduling

Shows current schedule status with clock icons.

### Admin Columns

A new **"Schedule"** column appears in the post list showing:
- ↓ Unpublish date (down arrow)
- ↑ Republish date (up arrow)

## Security Features

✅ **Implemented Security Measures:**

- Direct file access prevention (`ABSPATH` check)
- Capability checks (`manage_options`, `edit_post`)
- Nonce verification for form submissions and AJAX
- Input sanitization (`sanitize_text_field()`, `sanitize_email()`, `sanitize_key()`)
- Output escaping (`esc_html()`, `esc_attr()`, `esc_url()`)
- SQL injection prevention (proper `$wpdb` usage with types)
- AJAX nonce verification
- Post type validation

## Developer Hooks

### Actions

```php
// Before unpublishing (custom hook - add to plugin if needed)
do_action( 'yt_pps_before_unpublish', $post_id );

// Before republishing (custom hook - add to plugin if needed)
do_action( 'yt_pps_before_republish', $post_id );
```

### Filters

```php
// Modify unpublish status
add_filter( 'yt_pps_unpublish_status', function( $status, $post_id ) {
    return 'private'; // Force private instead of draft
}, 10, 2 );

// Modify notification email
add_filter( 'yt_pps_notification_email', function( $email, $post_id, $action ) {
    return 'custom@example.com';
}, 10, 3 );
```

### Programmatic Access

```php
// Get plugin instance
$scheduler = YT_Post_Publish_Scheduler::get_instance();

// Schedule a post programmatically
$post_id = 123;
update_post_meta( $post_id, '_yt_pps_unpublish_date', '2025-12-31 23:59:59' );
update_post_meta( $post_id, '_yt_pps_republish_date', '2026-01-01 00:00:00' );

$unpublish_timestamp = strtotime( get_gmt_from_date( '2025-12-31 23:59:59' ) );
$republish_timestamp = strtotime( get_gmt_from_date( '2026-01-01 00:00:00' ) );

wp_schedule_single_event( $unpublish_timestamp, 'yt_pps_unpublish_post', array( $post_id ) );
wp_schedule_single_event( $republish_timestamp, 'yt_pps_republish_post', array( $post_id ) );

// Clear a schedule
wp_clear_scheduled_hook( 'yt_pps_unpublish_post', array( $post_id ) );
wp_clear_scheduled_hook( 'yt_pps_republish_post', array( $post_id ) );
delete_post_meta( $post_id, '_yt_pps_unpublish_date' );
delete_post_meta( $post_id, '_yt_pps_republish_date' );
```

## Troubleshooting

### Scheduled Posts Not Executing

**Problem**: Posts aren't unpublishing/republishing at the scheduled time.

**Solutions**:
1. **Check WP-Cron**: WordPress cron requires site traffic or a real cron job
   ```bash
   # Add to system cron (recommended for production)
   */15 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron
   ```
2. **Disable WP-Cron** and use system cron in `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   ```
3. **Check scheduled events** using a plugin like "WP Crontrol"
4. **Verify server timezone** matches WordPress timezone

### Past Dates Not Working

**Problem**: Can't select dates in the past.

**Solution**: Enable **"Allow Past Dates"** in Settings → Publish Scheduler. This is disabled by default to prevent accidental backdating.

### Email Notifications Not Sending

**Problem**: Not receiving email notifications.

**Solutions**:
1. Verify email address in plugin settings
2. Check spam/junk folders
3. Test WordPress email functionality with a plugin like "Check Email"
4. Configure SMTP plugin (WP Mail SMTP recommended)

### Activity Log Not Showing

**Problem**: Activity log is empty.

**Solution**: Enable **"Log Actions"** in plugin settings. Historical actions won't be logged retroactively.

## Testing Checklist

- [x] Plugin activates without errors
- [x] Settings page displays correctly
- [x] Settings save properly with validation
- [x] Meta box appears on enabled post types
- [x] Unpublish schedule executes correctly
- [x] Republish schedule executes correctly
- [x] Email notifications send successfully
- [x] Activity log records actions
- [x] Admin columns display schedule info
- [x] AJAX clear schedule works
- [x] Cron events clear on deactivation
- [x] No PHP warnings or notices
- [x] Compatible with WordPress 5.8+
- [x] Works with PHP 7.4+

## WPCS Validation

Run PHP_CodeSniffer with WordPress standards:

```bash
phpcs --standard=WordPress class-yt-post-publish-scheduler.php
```

## Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **Database**: MySQL 5.6+ or MariaDB 10.1+

## License

GPL v2 or later

## Author

**Krasen Slavov**
- Website: https://krasenslavov.com
- GitHub: https://github.com/krasenslavov/yt-post-publish-scheduler

## Credits

Built following WordPress Plugin Handbook and WPCS guidelines.

## Support

For issues and feature requests, visit:
- [GitHub Issues](https://github.com/krasenslavov/yt-post-publish-scheduler/issues)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
