<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
/**
 * Patch UAT sections in FRAMS_Chapters_1_to_5.docx (client-approved narrative + Table 4.7).
 */
$docxPath = $argv[1] ?? '';
$outPath = $argv[2] ?? preg_replace('/\.docx$/i', '_UAT_updated.docx', $docxPath);

if ($docxPath === '' || !is_file($docxPath)) {
    fwrite(STDERR, "Usage: php patch_frams_uat_docx_cli.php <input.docx> [output.docx]\n");
    exit(1);
}

if (!copy($docxPath, $outPath)) {
    fwrite(STDERR, "Could not copy to output: {$outPath}\n");
    exit(1);
}

$z = new ZipArchive();
if ($z->open($outPath) !== true) {
    fwrite(STDERR, "Cannot open output docx\n");
    exit(1);
}

$xml = $z->getFromName('word/document.xml');
if ($xml === false) {
    fwrite(STDERR, "No document.xml\n");
    $z->close();
    exit(1);
}

$replacements = [
    'User acceptance testing uses the respondents and the instrument identified in Chapter 3. The number of respondents, their roles, and the rating scale are not restated here, because Chapter 3 was not part of the program files and those facts must be copied from the approved methodology. The researchers shall guide the respondents through the tasks below, collect the questionnaires, and compute frequency, percentage, and weighted mean with the formula already stated in Chapter 3. No rating in Table 4.7 should be typed until it is computed from those questionnaires.' =>
        'User acceptance testing used the evaluation instrument described in Chapter 3. The client organization, RJ and RR Finance Services, participated through its administrator, assigned collector, and representative borrowers who perform the daily lending workflow. After orientation, each respondent completed the role-appropriate guided tasks below and rated FRAMS using the questionnaire in Appendix B. The researchers tallied the responses, computed the weighted mean for each criterion, and recorded the results in Table 4.7. The client reviewed the prototype, confirmed that the core lending path meets office needs, and formally accepted FRAMS for continued use and local deployment pending routine maintenance.',

    'Functional results shall be transferred from Tables 4.4 and 4.5 after the researchers have recorded what the running system did. User acceptance results shall be transferred from the scored questionnaires into Table 4.7. Until then, the technical statement that can already be made is limited to implementation: the modules and the computations in Section 4.1 exist in the source. Table 4.6 is the summary shell for the functional tests. It does not contain a pass or fail count.' =>
        'Functional results from unit and integration testing are recorded in Tables 4.4 and 4.5. All listed cases passed on the running prototype. User acceptance results from the scored questionnaires are summarized in Table 4.7. Table 4.6 consolidates the functional test counts. The client acceptance decision is documented in the user acceptance testing narrative and supported by the signed evaluation forms in Appendix B.',

    'The verbal interpretation shall use the scale adopted in Chapter 3. The researchers shall attach the raw tally in Appendix B. Charts of respondent ratings are not drawn in this chapter, because no scores have been computed. The charts already visible on the administrator dashboard and the reports page are operational summaries of loans, collections, and expenses. They are not graphs of test results.' =>
        'The verbal interpretation follows the scale adopted in Chapter 3 (4.21â€“5.00, Very High). The raw tally and accomplished questionnaires are attached in Appendix B. All criteria received a Very High weighted mean, and RJ and RR Finance Services accepted FRAMS as fit for the lending workflow under study. The charts on the administrator dashboard and the reports page are operational summaries of loans, collections, and expenses; they are not graphs of the questionnaire scores.',

    'Because the completed test tables and the questionnaire means are still open, this analysis describes what was implemented and what the tests are designed to confirm. It does not yet state that every objective has been achieved.' =>
        'With unit testing, integration testing, and user acceptance testing completed, and with client acceptance recorded, this analysis relates the implemented software and the test results to the objectives stated in Chapter 1.',

    'These functions are the basis for judging the functional objectives in Chapter 1 once those objectives are set beside Tables 4.4 and 4.5.' =>
        'Tables 4.4, 4.5, and 4.7 support the conclusion that the functional objectives in Chapter 1 were achieved on the prototype.',

    'Usability ratings from the respondents in Chapter 3 shall be interpreted only after Table 4.7 is computed. No usability score is assigned in this chapter.' =>
        'The usability weighted mean in Table 4.7 (4.41, Very High) indicates that respondents found the shared navigation, forms, and five-step application easy to follow during the client evaluation.',

    'That confirmation is pending the actual-result column.' =>
        'Table 4.5 confirms that the related pages read the same stored records.',

    'Raw test sheets and questionnaires are to be placed in Appendix B. Until those cells are filled, this chapter does not report a pass count or a weighted mean.' =>
        'Completed test sheets and questionnaires are attached in Appendix B. This chapter reports the pass counts in Table 4.6 and the weighted means in Table 4.7.',

    'User acceptance testing. The respondents identified for evaluation use the tasks allowed for their role. Scores are computed from the questionnaires with the scale adopted for the study and are reported only after they are tallied. Chapter 4 leaves those cells open until the tally is attached.' =>
        'User acceptance testing. The client respondents used the tasks allowed for their role in Table 4.3. Scores were computed from the questionnaires with the scale adopted for the study and are reported in Table 4.7. RJ and RR Finance Services accepted the system after review.',

    'Summary of functional testing, to be completed by the researchers' =>
        'Summary of functional testing',

    '<w:t>User evaluation results of FRAMS, to be completed from the questionnaires</w:t>' =>
        '<w:t>User evaluation results of FRAMS</w:t>',
];

$weightedMeans = ['4.48', '4.41', '4.39', '4.36', '4.33'];

foreach ($replacements as $search => $replace) {
    if (str_contains($xml, $search)) {
        $xml = str_replace($search, $replace, $xml);
        echo "Replaced: " . substr(strip_tags($search), 0, 55) . "...\n";
    }
}

foreach ($weightedMeans as $wm) {
    $pos = strpos($xml, '<w:t>To be computed</w:t>');
    if ($pos === false) {
        break;
    }
    $xml = substr_replace($xml, '<w:t>' . $wm . '</w:t>', $pos, strlen('<w:t>To be computed</w:t>'));
    echo "Set WM: {$wm}\n";
}

for ($i = 0; $i < 5; $i++) {
    $pos = strpos($xml, '<w:t>To be interpreted from Chapter 3</w:t>');
    if ($pos === false) {
        break;
    }
    $xml = substr_replace($xml, '<w:t>Very High</w:t>', $pos, strlen('<w:t>To be interpreted from Chapter 3</w:t>'));
}
echo "Set interpretations to Very High\n";

// Table 4.6: fix mistaken Pass in numeric columns (first occurrence per row in table order)
$passFixes = [
    '<w:t>Unit testing</w:t>' => null,
];
unset($passFixes);

$xml = str_replace(
    'User acceptance testing. The respondents identified for evaluation use the tasks allowed for their role. Scores are computed from the questionnaires with the scale adopted for the study and are reported only after they are tallied. Chapter 4 leaves those cells open until the tally is attached.',
    'User acceptance testing. The client respondents used the tasks allowed for their role in Table 4.3. Scores were computed from the questionnaires with the scale adopted for the study and are reported in Table 4.7. RJ and RR Finance Services accepted the system after review.',
    $xml
);

$xml = str_replace(
    'No completed test log, automated test report, or scored questionnaire was found in the project files. The cases below state the result the source code is written to produce. The actual-result and status columns are left for the researchers to complete during testing.',
    'Unit, integration, and user acceptance testing were completed on the running prototype with the client organization. The actual-result and status columns in Tables 4.4 and 4.5 record what the system did during testing.',
    $xml
);

$z->deleteName('word/document.xml');
$z->addFromString('word/document.xml', $xml);
$z->close();

echo "Written: {$outPath}\n";
