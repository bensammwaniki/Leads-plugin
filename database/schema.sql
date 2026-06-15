CREATE TABLE wp_mcle_surveys (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL,
  description longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_survey_sections (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  survey_id bigint(20) unsigned NOT NULL,
  title varchar(255) NOT NULL,
  description longtext NULL,
  order_index int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_survey_questions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  section_id bigint(20) unsigned NOT NULL,
  question_text text NOT NULL,
  type varchar(20) NOT NULL,
  required tinyint(1) NOT NULL DEFAULT 0,
  order_index int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_question_options (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  question_id bigint(20) unsigned NOT NULL,
  label varchar(255) NOT NULL,
  value varchar(255) NOT NULL,
  price_impact decimal(12,2) NOT NULL DEFAULT 0,
  score_impact int(11) NOT NULL DEFAULT 0,
  order_index int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_leads (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  survey_id bigint(20) unsigned NOT NULL,
  session_id varchar(64) NOT NULL,
  total_price decimal(12,2) NOT NULL DEFAULT 0,
  lead_score int(11) NOT NULL DEFAULT 0,
  answers_json longtext NULL,
  pricing_json longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_lead_answers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lead_id bigint(20) unsigned NOT NULL,
  question_id bigint(20) unsigned NOT NULL,
  answer_value longtext NULL,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_cf7_integrations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  survey_id bigint(20) unsigned NOT NULL,
  cf7_form_id bigint(20) unsigned NOT NULL,
  mapping_rules longtext NULL,
  PRIMARY KEY  (id)
);

CREATE TABLE wp_mcle_lead_cf7_data (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lead_id bigint(20) unsigned NOT NULL,
  cf7_form_id bigint(20) unsigned NOT NULL,
  data_json longtext NULL,
);

CREATE TABLE wp_mcle_bookings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lead_id bigint(20) unsigned NOT NULL,
  meeting_type varchar(50) NOT NULL,
  location_type varchar(50) NOT NULL,
  location_name varchar(255) NULL,
  location_address text NULL,
  meeting_date date NOT NULL,
  meeting_time time NOT NULL,
  calendar_event_id varchar(255) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lead_id (lead_id),
  KEY meeting_date (meeting_date)
);
