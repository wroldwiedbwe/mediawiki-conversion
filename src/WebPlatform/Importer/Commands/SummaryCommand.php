<?php

/**
 * WebPlatform MediaWiki Conversion.
 **/

namespace WebPlatform\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

use WebPlatform\ContentConverter\Converter\MediaWikiToMarkdown;
use WebPlatform\ContentConverter\Model\MediaWikiDocument;
use WebPlatform\ContentConverter\Entity\MediaWikiRevision;
use WebPlatform\ContentConverter\Persistency\FileGitCommit;
use SimpleXMLElement;
use Prewk\XmlStringStreamer;

class SummaryCommand extends Command
{

    protected function convert(MediaWikiRevision $revision)
    {
        return $this->converter->apply($revision);
    }

    protected function configure()
    {
        $this
            ->setName('mediawiki:summary')
            ->setDescription(<<<DESCR
                Walk through MediaWiki dumpBackup XML file,
                summarize revisions and a suggested file name
                to store on a filesystem.
DESCR
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $header_style = new OutputFormatterStyle('white', 'black', array('bold'));
        $output->getFormatter()->setStyle('header', $header_style);

        $file = DUMP_DIR.'/main_full.xml';

        $streamer = XmlStringStreamer::createStringWalkerParser($file);
        $this->converter = new MediaWikiToMarkdown;

        $moreThanHundredRevs = array();
        $translations = array();
        $redirects = array();
        $url_sanity_redirects = array();
        $pages = array();
        $directlyOnRoot = array();
        $rev_count = array(); // So we can know what’s the average

        $counter = 1;
        $maxHops = 0;

        // Pages we have to make sure aren’t duplicate on the CMS prior
        // to the final migration.
        $temporary_acceptable_duplicates = array();
        //$temporary_acceptable_duplicates[] = 'css/selectors/pseudo-classes/:lang'; // DONE

        while ($node = $streamer->getNode()) {
            if ($maxHops > 0 && $maxHops === $counter) {
                $output->writeln(sprintf('Reached desired maximum of %d loops', $maxHops));
                $output->writeln('');
                $output->writeln('');
                break;
            }
            $pageNode = new SimpleXMLElement($node);
            if (isset($pageNode->title)) {

                $wikiDocument = new MediaWikiDocument($pageNode);

                $wikiRevision = $wikiDocument->getLatest();
                $file = new FileGitCommit($wikiRevision);
                $file->setFileName(MediaWikiDocument::toFileName($wikiDocument->getTitle()));
                $file_path  = $file->getFileName();
                $file_path .= (($wikiDocument->isTranslation()) ? null : '/index' ) . '.md';

                $title = $wikiDocument->getTitle();
                $revs  = $wikiDocument->getRevisions()->count();
                $is_translation = $wikiDocument->isTranslation();
                $redirect = $wikiDocument->getRedirect();
                $normalized_location = $file->getFileName();

                $output->writeln(sprintf('"%s":', $title));
                $output->writeln(sprintf('  - normalized: %s', $normalized_location));
                $output->writeln(sprintf('  - file: %s', $file_path));
                $output->writeln(sprintf('  - revisions: %d', $revs));

                $rev_count[] = $revs;

                // Which pages are directly on /wiki/foo. Are there some we
                // should move elsewhere such as the glossary items?
                if (count(explode('/', $title)) == 1 && $redirect === false) {
                    $directlyOnRoot[] = $title;
                }

                if ($revs > 99) {
                    $moreThanHundredRevs[] = sprintf('%s (%d)', $title, $revs);
                }

                if ($is_translation === true) {
                    $output->writeln(sprintf('  - language_code: %s', $wikiDocument->getLanguageCode()));
                    $translations[] = $title;
                }

                // The ones with invalid URL characters that shouldn’t be part of
                // a page name because they may confuse with their natural use (:,(,),!,?)
                if ($title !== $normalized_location) {
                    $url_sanity_redirects[$title] = $normalized_location;
                }

                // We have a number of pages, some of them had been
                // deleted or erased with a redirect left behind.
                //
                // Since we want to write to files all pages that currently
                // has content into a filesystem, we have to generate a file
                // name that can be stored into a filesystem. We therefore have
                // to normalize the names.
                //
                // We don’t want to have two entries with the same name.
                //
                // If a redirect (i.e. an empty file) exist, let’s set keep it
                // separate from the pages that still has content.
                //
                // Sanity check;
                // 1. Get list of redirects
                // 2. Get list of pages
                //
                // If we have a page duplicate, throw an exception!
                if ($redirect !== false) {
                    // Pages we know are redirects within MediaWiki, we won’t
                    // pass them within the $pages aray because they would be
                    // empty content with only a redirect anyway.
                    $output->writeln(sprintf('  - redirect: %s', $redirect));
                    $redirects[$normalized_location] = $redirect;
                } elseif (!in_array($normalized_location, array_keys($pages))) {
                    // Pages we know has content, lets count them!
                    $pages[$normalized_location] = $title;
                } elseif (in_array($title, $temporary_acceptable_duplicates)) {
                    // Lets not throw, we got that covered.
                } else {
                    // Hopefully we should never encounter this.
                    $previous = $pages[$normalized_location];
                    $duplicatePagesExceptionText =  "We have duplicate entry for %s it "
                                                   ."would be stored in %s which would override content of %s";
                    throw new \Exception(sprintf($duplicatePagesExceptionText, $title, $file_path, $previous));
                }

                $output->writeln('');
                $output->writeln('');
                ++$counter;
            }

        }

        /**
         * Work some numbers on number of edits
         *
         * - Average
         * - Median
         */
        $total_edits = 0;
        sort($rev_count);
        $edit_average = array_sum($rev_count)/$counter;

        // Calculate median
        $value_in_middle = floor(($counter-1)/2);
        if ($counter % 2) {
            // odd number, middle is the median
            $edit_median = $rev_count[$value_in_middle];
        } else {
            // even number, calculate avg of 2 medians
            $low = $rev_count[$value_in_middle];
            $high = $rev_count[$value_in_middle+1];
            $edit_median = (($low+$high)/2);
        }

        $output->writeln('---');
        $output->writeln('');
        $output->writeln('');

        $output->writeln('Pages with more than 100 revisions:');
        foreach ($moreThanHundredRevs as $r) {
            $output->writeln(sprintf('  - %s', $r));
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('---');
        $output->writeln('');
        $output->writeln('');

        $output->writeln('Available translations:');
        foreach ($translations as $t) {
            $output->writeln(sprintf('  - %s', $t));
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('---');
        $output->writeln('');
        $output->writeln('');

        $output->writeln('Redirects (from => to):');
        foreach ($redirects as $url => $redirect_to) {
            $output->writeln(sprintf(' - "%s": "%s"', $url, $redirect_to));
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('---');
        $output->writeln('');
        $output->writeln('');

        $output->writeln('URLs to return new Location (from => to):');
        foreach ($url_sanity_redirects as $title => $sanitized) {
            $output->writeln(sprintf(' - "%s": "%s"', $title, $sanitized));
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('---');
        $output->writeln('');
        $output->writeln('');

        $output->writeln('Pages not in a directory:');
        foreach ($directlyOnRoot as $title) {
            $output->writeln(sprintf(' - %s', $title));
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('---');
        $output->writeln('');
        $output->writeln('');

        $output->writeln('Numbers:');
        $output->writeln(sprintf('  - "iterations": %d', $counter));
        $output->writeln(sprintf('  - "content pages": %d', count($pages)));
        $output->writeln(sprintf('  - "redirects": %d', count($redirects)));
        $output->writeln(sprintf('  - "translated": %d', count($translations)));
        $output->writeln(sprintf('  - "not in a directory": %d', count($directlyOnRoot)));
        $output->writeln(sprintf('  - "redirects for URL sanity": %d', count($url_sanity_redirects)));
        $output->writeln(sprintf('  - "edits average": %d', $edit_average));
        $output->writeln(sprintf('  - "edits median": %d', $edit_median));

    }
}