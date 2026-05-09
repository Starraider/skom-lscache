CREATE TABLE tx_skomlscache_purge_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned NOT NULL DEFAULT '0',
    crdate int(11) unsigned NOT NULL DEFAULT '0',
    site_identifier varchar(100) NOT NULL DEFAULT '',
    source varchar(32) NOT NULL DEFAULT '',
    trigger_ref varchar(255) DEFAULT NULL,
    tags mediumtext NOT NULL,
    urls mediumtext NOT NULL,
    tag_count int(11) unsigned NOT NULL DEFAULT '0',
    outcome varchar(16) NOT NULL DEFAULT '',
    latency_ms int(11) unsigned NOT NULL DEFAULT '0',
    request_id varchar(36) NOT NULL DEFAULT '',

    PRIMARY KEY (uid),
    KEY idx_crdate (crdate),
    KEY idx_site_crdate (site_identifier, crdate),
    KEY idx_site_source (site_identifier, source),
    KEY idx_source (source),
    KEY idx_outcome (outcome),
    KEY idx_request_id (request_id)
);
