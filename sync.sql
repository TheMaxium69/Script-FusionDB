INSERT INTO `gamenium`.`game` (
    `id_giant_bomb`, `guid`, `name`, `aliases`, `api_detail_url`,
    `date_added`, `date_last_updated`, `deck`, `description`,
    `expected_release_day`, `expected_release_month`, `expected_release_year`,
    `image`, `image_tags`, `number_of_user_reviews`, `original_game_rating`,
    `original_release_date`, `platforms`, `site_detail_url`, `expected_release_quarter`
)
SELECT
    `id_giant_bomb`, `guid`, `name`, `aliases`, `api_detail_url`,
    `date_added`, `date_last_updated`, `deck`, `description`,
    `expected_release_day`, `expected_release_month`, `expected_release_year`,
    `image`, `image_tags`, `number_of_user_reviews`, `original_game_rating`,
    `original_release_date`, `platforms`, `site_detail_url`, `expected_release_quarter`
FROM `gamenium_test`.`game`
    ON DUPLICATE KEY UPDATE
                         `guid` = VALUES(`guid`),
                         `name` = VALUES(`name`),
                         `aliases` = VALUES(`aliases`),
                         `api_detail_url` = VALUES(`api_detail_url`),
                         `date_added` = VALUES(`date_added`),
                         `date_last_updated` = VALUES(`date_last_updated`),
                         `deck` = VALUES(`deck`),
                         `description` = VALUES(`description`),
                         `expected_release_day` = VALUES(`expected_release_day`),
                         `expected_release_month` = VALUES(`expected_release_month`),
                         `expected_release_year` = VALUES(`expected_release_year`),
                         `image` = VALUES(`image`),
                         `image_tags` = VALUES(`image_tags`),
                         `number_of_user_reviews` = VALUES(`number_of_user_reviews`),
                         `original_game_rating` = VALUES(`original_game_rating`),
                         `original_release_date` = VALUES(`original_release_date`),
                         `platforms` = VALUES(`platforms`),
                         `site_detail_url` = VALUES(`site_detail_url`),
                         `expected_release_quarter` = VALUES(`expected_release_quarter`);