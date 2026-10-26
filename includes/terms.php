<?php
declare(strict_types=1);

// JPCS System Terms and Agreement: shown on terms.php and in the sign-up dialog.

const JPCS_TERMS_VERSION = '2026-10-26';
const JPCS_TERMS_EFFECTIVE = 'October 26, 2026';
const JPCS_TERMS_CHECKBOX = 'I have read and agree to the JPCS System Terms and Agreement and acknowledge the applicable Privacy Notice.';

/** Adds the columns that record when a student accepted which version of the terms. */
function jpcs_terms_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $columns = [];
    foreach ($conn->query('SHOW COLUMNS FROM student_accounts') as $column) $columns[] = $column['Field'];
    if (!in_array('terms_version', $columns, true)) $conn->query('ALTER TABLE student_accounts ADD COLUMN terms_version VARCHAR(20) NULL');
    if (!in_array('terms_accepted_at', $columns, true)) $conn->query('ALTER TABLE student_accounts ADD COLUMN terms_accepted_at DATETIME NULL');
    $done = true;
}

/** Table of contents: [anchor => title]. */
function jpcs_terms_sections(): array
{
    return [
        'acceptance' => 'Acceptance of Terms', 'purpose' => 'Purpose of the System', 'registration' => 'User Registration',
        'security' => 'Account Security', 'acceptable-use' => 'Acceptable Use', 'photobooth' => 'Photobooth Rules',
        'voting' => 'Online Voting Rules', 'announcements' => 'Announcements and Official Information', 'events' => 'Events and Attendance',
        'privacy' => 'Data Privacy', 'sharing' => 'Use and Sharing of Information', 'retention' => 'Data Retention and Privacy Requests',
        'ip' => 'Intellectual Property', 'availability' => 'System Availability and Maintenance', 'issues' => 'Reporting Issues',
        'suspension' => 'Account Suspension or Termination', 'limitations' => 'System Limitations', 'changes' => 'Changes to These Terms',
        'contact' => 'Contact Information', 'acknowledgment' => 'User Acknowledgment',
    ];
}

/** The terms as HTML (static text, no user input). */
function jpcs_terms_html(): string
{
    $features = [
        ['Account Registration and Profile Management', 'Allows users to create accounts, provide the required information, and update their profiles when necessary.'],
        ['Announcements and Updates', 'Provides official announcements regarding JPCS events, meetings, activities, deadlines, and other important information.'],
        ['Event Management', 'Supports event registration, participant management, and coordination of JPCS activities.'],
        ['Attendance Monitoring', 'Allows authorized personnel to record and monitor attendance during meetings, events, and other organizational activities.'],
        ['Photobooth', "Allows users to take, view, and access photographs through the JPCS photobooth during designated events, depending on the system's available features."],
        ['Online Voting', 'Allows eligible users to participate in authorized JPCS elections, polls, and voting activities. Users must follow the established voting rules and must not manipulate results or submit unauthorized votes.'],
        ['Submission of Requirements', 'Allows users to submit documents, forms, and other requirements related to JPCS activities when the feature is available.'],
        ['Administrative Functions', 'Allows authorized officers and administrators to manage accounts, announcements, events, attendance, photographs, voting activities, and other system functions according to their assigned permissions.'],
    ];
    $featureHtml = '';
    foreach ($features as $i => [$title, $text]) {
        $featureHtml .= '<h4>2.' . ($i + 1) . '. ' . $title . '</h4><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $n = 0;
    $s = static function (string $id, string $body) use (&$n): string {
        $n++;
        return '<section id="terms-' . $id . '"><h3><span>' . $n . '.</span> ' . jpcs_terms_sections()[$id] . '</h3>' . $body . '</section>';
    };

    return '<div class="terms-doc">'
        . '<header class="terms-doc__head"><p class="terms-doc__org">Junior Philippine Computer Society – City of Malabon University Chapter (JPCS-CMU)</p>'
        . '<h2>Terms and Agreement</h2><p class="terms-doc__date">Effective Date: ' . JPCS_TERMS_EFFECTIVE . '</p></header>'
        . '<p>Welcome to the JPCS System of the Junior Philippine Computer Society – City of Malabon University Chapter (JPCS-CMU).</p>'
        . '<p>This system provides students and organization members with access to JPCS announcements, events, attendance monitoring, photobooth services, online voting, and other organizational activities.</p>'
        . '<p>By creating an account or using the system, you agree to follow the terms and conditions outlined below.</p>'
        . $s('acceptance', '<p>By accessing or using the JPCS System, you confirm that you have read and understood these Terms and Agreement and agree to comply with the rules stated in this document.</p><p>If you do not agree with these terms, please refrain from using the system.</p>')
        . $s('purpose', '<p>The JPCS System serves as a centralized platform for managing organizational activities and providing digital services to students and JPCS members.</p><p>The system includes the following features:</p>' . $featureHtml . '<p>The system must only be used for legitimate academic, organizational, and event-related purposes.</p>')
        . $s('registration', "<p>Users must provide accurate and complete information when creating an account. Impersonation, fraudulent registration, and the unauthorized use of another person's information are prohibited.</p><p>Users must meet the eligibility requirements for specific activities, particularly organizational elections and voting.</p>")
        . $s('security', "<p>Users are responsible for keeping their login credentials confidential and protecting their accounts from unauthorized access.</p><p>Users must not share their accounts, access another person's account without permission, or attempt to bypass system security.</p><p>Any suspected unauthorized access or suspicious activity must be reported to the system administrator.</p>")
        . $s('acceptable-use', "<p>Users must use the JPCS System responsibly and respectfully.</p><p>The following activities are prohibited:</p><ul><li>Unauthorized access to accounts, records, or administrative functions.</li><li>Modification or deletion of records without permission.</li><li>Uploading malicious files or harmful software.</li><li>Impersonation, harassment, or misuse of another person's information.</li><li>Manipulation of attendance records, event registrations, or voting results.</li><li>Unauthorized distribution of private or confidential information.</li><li>Any activity that compromises the system's security or violates applicable laws and university policies.</li></ul><p>Violations may result in account restrictions or suspension, subject to applicable university policies and due process.</p>")
        . $s('photobooth', '<p>The photobooth is provided for users to capture and keep memories of JPCS events and activities.</p><p>Users must respect the privacy of individuals appearing in photographs. Photos must not be used to harass, embarrass, mislead, or harm another person.</p><p>Photographs may be viewed or downloaded through the system, depending on the available features and access permissions.</p><p>Using photographs for public announcements, promotional materials, or social media posts must comply with applicable privacy requirements. Where required, appropriate consent must be obtained.</p><p>Users who have concerns about a photograph may contact the system administrator or designated privacy contact to request assistance.</p>')
        . $s('voting', "<p>The online voting feature is intended for authorized JPCS elections, polls, and other voting activities.</p><p>Only eligible users may participate in a voting activity. Users must follow the announced voting period and election rules.</p><p>Where only one vote per eligible voter is allowed, submitting multiple votes or using duplicate accounts to bypass restrictions is prohibited.</p><p>Users must not impersonate other voters, manipulate vote counts, access restricted election records, or interfere with the voting process.</p><p>Voting information must be handled according to the applicable election rules and Privacy Notice. Where secret ballots are required, appropriate safeguards must be implemented to protect the confidentiality of voters' choices.</p><p>Any technical issue or suspected voting irregularity should be reported to the authorized election committee or system administrator.</p>")
        . $s('announcements', '<p>Only authorized personnel may publish official announcements through the system.</p><p>Users must not distribute fabricated announcements, impersonate JPCS representatives, or alter official information to mislead others.</p><p>Questionable information should be verified through official JPCS-CMU communication channels.</p>')
        . $s('events', '<p>Users must provide accurate information when registering for events or participating in attendance monitoring.</p><p>Falsifying attendance, recording attendance for another person without authorization, or modifying attendance records without permission is prohibited.</p><p>Errors in attendance or registration records may be reported to an authorized officer or administrator for correction.</p>')
        . $s('privacy', "<p>JPCS-CMU is committed to protecting users' personal information in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173) and applicable university policies.</p><p>Depending on the features used, the system may collect names, student information, contact details, account information, attendance records, event registrations, photographs, voting eligibility information, and technical records necessary for system security.</p><p>Personal information shall be processed only for specified and legitimate purposes with an appropriate legal basis. Access must be limited to authorized personnel, and reasonable safeguards must be implemented to prevent unauthorized access, disclosure, loss, or misuse.</p><p>Users should review the applicable Privacy Notice for details about data collection, processing, retention, sharing, and their rights as data subjects.</p>")
        . $s('sharing', '<p>Personal information must not be shared with unauthorized individuals or used for purposes unrelated to those explained in the applicable Privacy Notice without an appropriate legal basis.</p><p>Information may be accessed by authorized university personnel or appropriate service providers when necessary for legitimate purposes and permitted by law.</p><p>Agreeing to these terms does not automatically constitute consent to every possible use of personal information. Separate consent shall be obtained when required.</p>')
        . $s('retention', "<p>Personal information shall be retained only for as long as necessary for its intended purposes, subject to applicable legal requirements and university recordkeeping policies.</p><p>Users may contact the university's designated Data Protection Officer or privacy contact to ask questions or exercise applicable privacy rights, including requesting access, correction, or deletion when permitted by law.</p><p>Some records may need to be retained where required by law or supported by another valid legal basis.</p>")
        . $s('ip', "<p>The system's design, branding, interface, and original organizational materials must not be copied, modified, distributed, or used commercially without the necessary authorization.</p><p>Users retain applicable rights to content they submit, subject to applicable law and any valid permissions granted for operating the system or conducting authorized JPCS activities.</p>")
        . $s('availability', "<p>JPCS-CMU will make reasonable efforts to keep the system accessible and operational. However, interruptions may occur due to maintenance, technical issues, security concerns, or circumstances beyond the organization's control.</p><p>Features may be updated, temporarily disabled, or discontinued when necessary. Significant changes or interruptions will be communicated through official channels whenever reasonably possible.</p>")
        . $s('issues', '<p>Users should report technical problems, suspicious account activity, privacy concerns, inaccurate records, or suspected voting irregularities to the authorized system administrator or designated organization representative.</p><p>Users must not exploit system vulnerabilities, access restricted information, or conduct unauthorized security testing.</p>')
        . $s('suspension', '<p>JPCS-CMU may restrict or suspend access for violations of these terms, unauthorized access, fraudulent activity, manipulation of voting results, or misuse of personal information.</p><p>Where appropriate, affected users will be informed of the reason and given an opportunity to respond in accordance with university policies and due process.</p><p>Records that must be retained under applicable law or university requirements may remain in the system after an account is suspended or terminated.</p>')
        . $s('limitations', '<p>Although reasonable efforts will be made to maintain system reliability and security, technical errors, service interruptions, and inaccurate information may occasionally occur.</p><p>Users should report problems through the proper channels rather than attempt unauthorized modifications.</p><p>Nothing in these terms excludes any rights or responsibilities that cannot legally be excluded.</p>')
        . $s('changes', '<p>These Terms and Agreement may be updated to reflect changes in system features, organizational procedures, security requirements, or applicable laws and policies.</p><p>Significant changes will be communicated through appropriate channels. Where required, users will be asked to provide renewed acknowledgment or consent.</p><p>The latest version will be made available through the JPCS System or another official communication channel.</p>')
        . $s('contact', "<p>For questions, technical assistance, account concerns, voting issues, or privacy requests, users may contact the authorized JPCS-CMU system administrator or the university's designated privacy contact.</p>"
            . '<dl class="terms-contact"><div><dt>Organization</dt><dd>Junior Philippine Computer Society – City of Malabon University Chapter (JPCS-CMU)</dd></div>'
            . '<div><dt>System Administrator</dt><dd>Samantha Yvette</dd></div>'
            . '<div><dt>Official Email</dt><dd><a href="mailto:samanthayvette041@gmail.com">samanthayvette041@gmail.com</a></dd></div>'
            . '<div><dt>Data Protection Officer / Privacy Contact</dt><dd>Samantha Yvette / Shaina Toledo</dd></div></dl>')
        . $s('acknowledgment', '<p>By checking the agreement box, I confirm that I have read and understood these Terms and Agreement, agree to use the JPCS System responsibly, will protect my account credentials, will follow the photobooth and voting rules, and understand that my personal information will be handled according to the applicable Privacy Notice and relevant laws.</p><p>I understand that violations may result in appropriate account restrictions or other action in accordance with university policies and due process.</p>')
        . '<p class="terms-doc__end">End of Terms and Agreement</p>'
        . '</div>';
}
