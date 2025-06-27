#!/usr/bin/php8.4
<?php
/**
 * @package     R3D Post By Mail
 * @subpackage  CLI
 * @author      Richard Dvorak <info@r3d.de>
 * @copyright   Copyright (C) 2025 Richard Dvorak. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @version     0.6.3 (2025-06-27)
 * 
 * CLI script for automatically importing and publishing blog posts
 * from an IMAP mailbox into Joomla 5 (as com_content articles).
 * 
 * This script processes HTML content, detects “MORE:” separators,
 * automatically stores embedded or attached images in the Joomla media path,
 * and updates all necessary tables for visibility in the Joomla backend.
 * Only new, unread emails will be processed.
 * 
 * EXAMPLE EMAIL:
 * SUBJECT: Test Blog 1     (becomes the article title)
 * EMAIL BODY:
 * A first paragraph, any length.
 * 
 * MORE:                   (converted to Joomla's Readmore separator)
 * Any number of additional paragraphs, any length.
 * 
 * NO SIGNATURE! Otherwise it will be included in the article content.
 * ---
 * ATTACHMENT: image.jpg   (allowed: jpg, JPEG, png, PNG, gif, GIF, webp, WEBP)
 * 
 * PLEASE REPLACE THE PLACEHOLDER VALUES IN THE SCRIPT, lines 76 to 81:
 * YOUR_IMAP_SERVERNAME     modify port and TLS settings if needed (default: SSL 993)
 * YOUR_IMAP_USERNAME       your IMAP login
 * YOUR_IMAP_PASSWORD       your IMAP password
 * ALLOWED_SENDER_EMAIL     to ensure security, all allowed senders must be listed here
 * YOUR_CATEGORY_ID         the Joomla article category – e.g. “Blog” with ID=14
 * YOUR_USER_ID             the Joomla user ID that will be used as article author
 * 
 * Requirements:
 * - A dedicated email address with IMAP access
 * - PHP 8.4 CLI
 * - Joomla 5.x with com_content enabled
 * - Write access to /images/blog/YYYY-MM/
 * - CRONJOB: must run as the WEBUSER! Otherwise, images and folders
 *   will be created as root and become undeletable in Joomla.
 *   Example CRON:
 *   INTERVAL web771 /usr/bin/php8.4 /var/www/clients/client221/web771/web/cli/r3dpostbymail.php
 */

use Joomla\CMS\Application\CliApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;

define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__));

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
require_once JPATH_BASE . '/libraries/vendor/autoload.php';

class R3DPostByMailCli extends CliApplication
{
    public function doExecute()
    {
        $_SERVER['HTTP_HOST'] = 'www.colmarpocket.com';
        $_SERVER['REQUEST_URI'] = '/';

        Factory::$application = Factory::getContainer()->get(SiteApplication::class);
        Factory::getLanguage()->load('com_content', JPATH_ADMINISTRATOR);

        $imapHost = '{YOURSMTPSERVER:993/imap/ssl}INBOX';
        $imapUser = 'YOUR_IMAP_USERNAME';
        $imapPass = 'YOUR_IMAP_PASSWORD';
        $allowed = ['ALLOWED_SENDER_EMAIL_1', 'ALLOWED_SENDER_EMAIL_2', 'ALLOWED_SENDER_EMAIL_3'];
        $catid = YOUR_CATEGORY_ID;
        $userid = YOUR_USER_ID;

        $inbox = imap_open($imapHost, $imapUser, $imapPass);
        if (!$inbox) {
            $this->out('[ERROR] Cannot connect to IMAP: ' . imap_last_error());
            return;
        }

        $emails = imap_search($inbox, 'UNSEEN');
        if (!$emails) {
            $this->out('[INFO] No new emails.');
            imap_close($inbox);
            return;
        }

        foreach ($emails as $email_number) {
            $overview = imap_fetch_overview($inbox, $email_number, 0)[0];
            $structure = imap_fetchstructure($inbox, $email_number);

            $body = imap_fetchbody($inbox, $email_number, 1.1);
            if (trim($body) === '') {
                $body = imap_fetchbody($inbox, $email_number, 1);
            }

            $from = $overview->from ?? '';
            $subject = trim($overview->subject ?? '(Kein Titel)');
            $content = trim($body);

            $fromClean = strtolower(trim(preg_match('/<(.+?)>/', $from, $m) ? $m[1] : $from));
            if (!in_array($fromClean, array_map('strtolower', $allowed))) {
                $this->out("[SKIP] Sender not allowed: $from");
                continue;
            }

            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName('#__content'))
                ->where('title = ' . $db->quote($subject));
            $db->setQuery($query);
            if ($db->loadResult()) {
                $this->out("[SKIP] Already exists: $subject");
                continue;
            }

            if (str_contains($content, 'MORE:')) {
                [$intro, $full] = explode('MORE:', $content, 2);
                $introtext = convertPlaintextToHtmlParagraphs(trim($intro));
                $fulltext = convertPlaintextToHtmlParagraphs(trim($full));
            } else {
                $parts = preg_split('/\R\R+/', trim($content), 2);
                $introtext = convertPlaintextToHtmlParagraphs(trim($parts[0]));
                $fulltext = convertPlaintextToHtmlParagraphs(trim($parts[1] ?? ''));
            }

            $imagePath = $this->extractFirstImage($inbox, $email_number, $structure);
            $imagesJson = '';

            if ($imagePath) {
                $relPath = $this->storeImage($imagePath);
                if ($relPath) {
                    [$width, $height] = getimagesize(JPATH_BASE . '/' . $relPath);
                    $imagesJson = json_encode([
                        'image_intro' => '',
                        'image_intro_alt' => '',
                        'float_intro' => '',
                        'image_intro_caption' => '',
                        'image_fulltext' => $relPath . '#joomlaImage://local-' . $relPath . "?width=$width&height=$height",
                        'image_fulltext_alt' => '',
                        'float_fulltext' => '',
                        'image_fulltext_caption' => ''
                    ]);
                    unlink($imagePath);
                }
            }

            Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/tables');
            $article = Table::getInstance('Content', 'JTable');

            $now = Factory::getDate()->toSql();
            $article->title = $subject;
            $article->alias = OutputFilter::stringURLSafe($subject);
            $article->introtext = $introtext;
            $article->fulltext = $fulltext;
            $article->catid = $catid;
            $article->created = $now;
            $article->publish_up = $now;
            $article->created_by = $userid;
            $article->state = 1;
            $article->language = '*';
            $article->access = 1;
            $article->images = $imagesJson ?: '{}';
            $article->urls = '{}';
            $article->attribs = '{}';
            $article->metakey = '';
            $article->metadesc = '';
            $article->metadata = '{}';
            $article->version = 1;

            if ($article->store()) {
                $this->out("[POSTED] $subject");
                $id = (int) $article->id;

                // Typ-ID
                $query = $db->getQuery(true)
                    ->select('type_id')
                    ->from($db->quoteName('#__content_types'))
                    ->where('type_alias = ' . $db->quote('com_content.article'));
                $db->setQuery($query);
                $typeId = (int) $db->loadResult();

                // alte Einträge löschen
                $db->setQuery("DELETE FROM `#__ucm_content` WHERE `core_content_item_id` = $id")->execute();
                $db->setQuery("DELETE FROM `#__ucm_base` WHERE `ucm_item_id` = $id")->execute();
                $db->setQuery("DELETE FROM `#__workflow_associations` WHERE `item_id` = $id AND `extension` = 'com_content.article'")->execute();

                // UCM content einfügen (mit korrekt NULL)
                $values = [
                    $db->quote($id),
                    $db->quote($article->title),
                    $db->quote($article->alias),
                    $db->quote($article->introtext . $article->fulltext),
                    $db->quote($article->state),
                    'NULL',
                    $db->quote($article->access),
                    $db->quote($article->attribs),
                    $db->quote($article->metadata),
                    $db->quote($article->created),
                    $db->quote($article->created_by),
                    $db->quote($article->created),
                    '0',
                    $db->quote($article->publish_up),
                    'NULL',
                    $db->quote($article->images),
                    $db->quote($article->urls),
                    $db->quote($article->language),
                    (int) ($article->featured ?? 0),
                    $db->quote('com_content.article'),
                    $typeId,
                    '0',
                    '1',
                    '0',
                    $db->quote($article->metakey),
                    $db->quote($article->metadesc),
                    $db->quote($article->catid)
                ];
                $query = $db->getQuery(true)
                    ->insert($db->quoteName('#__ucm_content'))
                    ->columns([
                        'core_content_item_id',
                        'core_title',
                        'core_alias',
                        'core_body',
                        'core_state',
                        'core_checked_out_time',
                        'core_access',
                        'core_params',
                        'core_metadata',
                        'core_created_time',
                        'core_created_user_id',
                        'core_modified_time',
                        'core_modified_user_id',
                        'core_publish_up',
                        'core_publish_down',
                        'core_images',
                        'core_urls',
                        'core_language',
                        'core_featured',
                        'core_type_alias',
                        'core_type_id',
                        'core_hits',
                        'core_version',
                        'core_ordering',
                        'core_metakey',
                        'core_metadesc',
                        'core_catid'
                    ])
                    ->values(implode(',', $values));
                $db->setQuery($query)->execute();

                $db->setQuery("INSERT INTO `#__ucm_base` (`ucm_item_id`, `ucm_type_id`, `ucm_language_id`) VALUES ($id, $typeId, 1)")->execute();
                $db->setQuery("INSERT INTO `#__workflow_associations` (`item_id`, `stage_id`, `extension`) VALUES ($id, 1, " . $db->quote('com_content.article') . ")")->execute();
            } else {
                $this->out("[ERROR] Failed to store: " . $article->getError());
            }
        }

        imap_close($inbox);
    }

    private function extractFirstImage($inbox, $email_number, $structure)
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!isset($structure->parts))
            return null;

        foreach ($structure->parts as $i => $part) {
            $disposition = strtolower($part->disposition ?? '');
            $subtype = strtolower($part->subtype ?? '');

            if (!in_array($subtype, $allowedExt))
                continue;
            if (!in_array($disposition, ['attachment', 'inline']))
                continue;

            $filename = 'img_' . uniqid() . '.' . $subtype;
            $content = imap_fetchbody($inbox, $email_number, $i + 1);
            $content = ($part->encoding == 3) ? base64_decode($content) : quoted_printable_decode($content);

            $tmpPath = sys_get_temp_dir() . '/' . $filename;
            file_put_contents($tmpPath, $content);
            return $tmpPath;
        }

        return null;
    }

    private function storeImage($tmpPath)
    {
        $dateFolder = date('Y-m-d');
        $folder = 'images/blog/' . $dateFolder;
        $fullFolder = JPATH_BASE . '/' . $folder;

        if (!Folder::exists($fullFolder)) {
            Folder::create($fullFolder);
        }

        $filename = basename($tmpPath);
        $target = $folder . '/' . $filename;
        $dest = JPATH_BASE . '/' . $target;

        if (File::exists($dest)) {
            $filename = uniqid() . '_' . $filename;
            $target = $folder . '/' . $filename;
            $dest = JPATH_BASE . '/' . $target;
        }

        File::copy($tmpPath, $dest);
        chown($dest, 'web771');
        chgrp($dest, 'client221');
        chmod($dest, 0644);

        return $target;
    }

    public function getName(): string
    {
        return 'R3DPostByMail';
    }
}
function convertPlaintextToHtmlParagraphs(string $text): string
{
    // Normiere Zeilenenden
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Behandle Sondermarker (z.B. "MORE:")
    $text = preg_replace('/^\s*MORE:\s*$/mi', "\n\n<!--more-->\n\n", $text);

    // Entferne harte Umbrüche innerhalb von Fließtext
    $text = preg_replace("/([^\n])\n(?=[^\n])/", '$1 ', $text);

    // Mehrfache Zeilenumbrüche => Absatz
    $paragraphs = preg_split("/\n{2,}/", $text);

    // In <p>...</p> hüllen
    $html = '';
    foreach ($paragraphs as $p) {
        $trimmed = trim($p);
        if ($trimmed !== '') {
            $html .= '<p>' . htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' . "\n";
        }
    }

    return $html;
}


CliApplication::getInstance(R3DPostByMailCli::class)->execute();
