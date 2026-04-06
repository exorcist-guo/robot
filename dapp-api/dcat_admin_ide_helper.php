<?php

/**
 * A helper file for Dcat Admin, to provide autocomplete information to your IDE
 *
 * This file should not be included in your code, only analyzed by your IDE!
 *
 * @author jqh <841324345@qq.com>
 */
namespace Dcat\Admin {
    use Illuminate\Support\Collection;

    /**
     * @property Grid\Column|Collection id
     * @property Grid\Column|Collection name
     * @property Grid\Column|Collection type
     * @property Grid\Column|Collection version
     * @property Grid\Column|Collection detail
     * @property Grid\Column|Collection created_at
     * @property Grid\Column|Collection updated_at
     * @property Grid\Column|Collection is_enabled
     * @property Grid\Column|Collection parent_id
     * @property Grid\Column|Collection order
     * @property Grid\Column|Collection icon
     * @property Grid\Column|Collection uri
     * @property Grid\Column|Collection extension
     * @property Grid\Column|Collection permission_id
     * @property Grid\Column|Collection menu_id
     * @property Grid\Column|Collection slug
     * @property Grid\Column|Collection http_method
     * @property Grid\Column|Collection http_path
     * @property Grid\Column|Collection role_id
     * @property Grid\Column|Collection user_id
     * @property Grid\Column|Collection value
     * @property Grid\Column|Collection username
     * @property Grid\Column|Collection password
     * @property Grid\Column|Collection avatar
     * @property Grid\Column|Collection email
     * @property Grid\Column|Collection wx_openid
     * @property Grid\Column|Collection remember_token
     * @property Grid\Column|Collection task_id
     * @property Grid\Column|Collection subtask_id
     * @property Grid\Column|Collection nickname
     * @property Grid\Column|Collection is_video
     * @property Grid\Column|Collection comment
     * @property Grid\Column|Collection status
     * @property Grid\Column|Collection chain_id
     * @property Grid\Column|Collection contract_address
     * @property Grid\Column|Collection block_number
     * @property Grid\Column|Collection time_stamp
     * @property Grid\Column|Collection tx_hash
     * @property Grid\Column|Collection block_hash
     * @property Grid\Column|Collection nonce
     * @property Grid\Column|Collection transaction_index
     * @property Grid\Column|Collection from_address
     * @property Grid\Column|Collection to_address
     * @property Grid\Column|Collection gas
     * @property Grid\Column|Collection gas_price
     * @property Grid\Column|Collection cumulative_gas_used
     * @property Grid\Column|Collection gas_used
     * @property Grid\Column|Collection confirmations
     * @property Grid\Column|Collection is_error
     * @property Grid\Column|Collection txreceipt_status
     * @property Grid\Column|Collection input
     * @property Grid\Column|Collection method_id
     * @property Grid\Column|Collection function_name
     * @property Grid\Column|Collection uuid
     * @property Grid\Column|Collection connection
     * @property Grid\Column|Collection queue
     * @property Grid\Column|Collection payload
     * @property Grid\Column|Collection exception
     * @property Grid\Column|Collection failed_at
     * @property Grid\Column|Collection member_id
     * @property Grid\Column|Collection amount
     * @property Grid\Column|Collection contract_dynamic_id
     * @property Grid\Column|Collection performance_record_id
     * @property Grid\Column|Collection from_grab_id
     * @property Grid\Column|Collection remark
     * @property Grid\Column|Collection pid
     * @property Grid\Column|Collection deep
     * @property Grid\Column|Collection path
     * @property Grid\Column|Collection address
     * @property Grid\Column|Collection level
     * @property Grid\Column|Collection performance
     * @property Grid\Column|Collection total_earnings
     * @property Grid\Column|Collection total_consumption
     * @property Grid\Column|Collection total_grab_count
     * @property Grid\Column|Collection token
     * @property Grid\Column|Collection tokenable_type
     * @property Grid\Column|Collection tokenable_id
     * @property Grid\Column|Collection abilities
     * @property Grid\Column|Collection last_used_at
     * @property Grid\Column|Collection expires_at
     * @property Grid\Column|Collection app_name
     * @property Grid\Column|Collection admin_id
     * @property Grid\Column|Collection attr_name
     * @property Grid\Column|Collection attr_type
     * @property Grid\Column|Collection attr_value
     * @property Grid\Column|Collection sort
     * @property Grid\Column|Collection account_id
     * @property Grid\Column|Collection query_name
     * @property Grid\Column|Collection match_name
     * @property Grid\Column|Collection replys
     * @property Grid\Column|Collection sequence
     * @property Grid\Column|Collection batch_id
     * @property Grid\Column|Collection family_hash
     * @property Grid\Column|Collection should_display_on_index
     * @property Grid\Column|Collection content
     * @property Grid\Column|Collection entry_uuid
     * @property Grid\Column|Collection tag
     * @property Grid\Column|Collection email_verified_at
     * @property Grid\Column|Collection image
     * @property Grid\Column|Collection browse_num
     * @property Grid\Column|Collection category_id
     * @property Grid\Column|Collection account
     * @property Grid\Column|Collection send_limit
     *
     * @method Grid\Column|Collection id(string $label = null)
     * @method Grid\Column|Collection name(string $label = null)
     * @method Grid\Column|Collection type(string $label = null)
     * @method Grid\Column|Collection version(string $label = null)
     * @method Grid\Column|Collection detail(string $label = null)
     * @method Grid\Column|Collection created_at(string $label = null)
     * @method Grid\Column|Collection updated_at(string $label = null)
     * @method Grid\Column|Collection is_enabled(string $label = null)
     * @method Grid\Column|Collection parent_id(string $label = null)
     * @method Grid\Column|Collection order(string $label = null)
     * @method Grid\Column|Collection icon(string $label = null)
     * @method Grid\Column|Collection uri(string $label = null)
     * @method Grid\Column|Collection extension(string $label = null)
     * @method Grid\Column|Collection permission_id(string $label = null)
     * @method Grid\Column|Collection menu_id(string $label = null)
     * @method Grid\Column|Collection slug(string $label = null)
     * @method Grid\Column|Collection http_method(string $label = null)
     * @method Grid\Column|Collection http_path(string $label = null)
     * @method Grid\Column|Collection role_id(string $label = null)
     * @method Grid\Column|Collection user_id(string $label = null)
     * @method Grid\Column|Collection value(string $label = null)
     * @method Grid\Column|Collection username(string $label = null)
     * @method Grid\Column|Collection password(string $label = null)
     * @method Grid\Column|Collection avatar(string $label = null)
     * @method Grid\Column|Collection email(string $label = null)
     * @method Grid\Column|Collection wx_openid(string $label = null)
     * @method Grid\Column|Collection remember_token(string $label = null)
     * @method Grid\Column|Collection task_id(string $label = null)
     * @method Grid\Column|Collection subtask_id(string $label = null)
     * @method Grid\Column|Collection nickname(string $label = null)
     * @method Grid\Column|Collection is_video(string $label = null)
     * @method Grid\Column|Collection comment(string $label = null)
     * @method Grid\Column|Collection status(string $label = null)
     * @method Grid\Column|Collection chain_id(string $label = null)
     * @method Grid\Column|Collection contract_address(string $label = null)
     * @method Grid\Column|Collection block_number(string $label = null)
     * @method Grid\Column|Collection time_stamp(string $label = null)
     * @method Grid\Column|Collection tx_hash(string $label = null)
     * @method Grid\Column|Collection block_hash(string $label = null)
     * @method Grid\Column|Collection nonce(string $label = null)
     * @method Grid\Column|Collection transaction_index(string $label = null)
     * @method Grid\Column|Collection from_address(string $label = null)
     * @method Grid\Column|Collection to_address(string $label = null)
     * @method Grid\Column|Collection gas(string $label = null)
     * @method Grid\Column|Collection gas_price(string $label = null)
     * @method Grid\Column|Collection cumulative_gas_used(string $label = null)
     * @method Grid\Column|Collection gas_used(string $label = null)
     * @method Grid\Column|Collection confirmations(string $label = null)
     * @method Grid\Column|Collection is_error(string $label = null)
     * @method Grid\Column|Collection txreceipt_status(string $label = null)
     * @method Grid\Column|Collection input(string $label = null)
     * @method Grid\Column|Collection method_id(string $label = null)
     * @method Grid\Column|Collection function_name(string $label = null)
     * @method Grid\Column|Collection uuid(string $label = null)
     * @method Grid\Column|Collection connection(string $label = null)
     * @method Grid\Column|Collection queue(string $label = null)
     * @method Grid\Column|Collection payload(string $label = null)
     * @method Grid\Column|Collection exception(string $label = null)
     * @method Grid\Column|Collection failed_at(string $label = null)
     * @method Grid\Column|Collection member_id(string $label = null)
     * @method Grid\Column|Collection amount(string $label = null)
     * @method Grid\Column|Collection contract_dynamic_id(string $label = null)
     * @method Grid\Column|Collection performance_record_id(string $label = null)
     * @method Grid\Column|Collection from_grab_id(string $label = null)
     * @method Grid\Column|Collection remark(string $label = null)
     * @method Grid\Column|Collection pid(string $label = null)
     * @method Grid\Column|Collection deep(string $label = null)
     * @method Grid\Column|Collection path(string $label = null)
     * @method Grid\Column|Collection address(string $label = null)
     * @method Grid\Column|Collection level(string $label = null)
     * @method Grid\Column|Collection performance(string $label = null)
     * @method Grid\Column|Collection total_earnings(string $label = null)
     * @method Grid\Column|Collection total_consumption(string $label = null)
     * @method Grid\Column|Collection total_grab_count(string $label = null)
     * @method Grid\Column|Collection token(string $label = null)
     * @method Grid\Column|Collection tokenable_type(string $label = null)
     * @method Grid\Column|Collection tokenable_id(string $label = null)
     * @method Grid\Column|Collection abilities(string $label = null)
     * @method Grid\Column|Collection last_used_at(string $label = null)
     * @method Grid\Column|Collection expires_at(string $label = null)
     * @method Grid\Column|Collection app_name(string $label = null)
     * @method Grid\Column|Collection admin_id(string $label = null)
     * @method Grid\Column|Collection attr_name(string $label = null)
     * @method Grid\Column|Collection attr_type(string $label = null)
     * @method Grid\Column|Collection attr_value(string $label = null)
     * @method Grid\Column|Collection sort(string $label = null)
     * @method Grid\Column|Collection account_id(string $label = null)
     * @method Grid\Column|Collection query_name(string $label = null)
     * @method Grid\Column|Collection match_name(string $label = null)
     * @method Grid\Column|Collection replys(string $label = null)
     * @method Grid\Column|Collection sequence(string $label = null)
     * @method Grid\Column|Collection batch_id(string $label = null)
     * @method Grid\Column|Collection family_hash(string $label = null)
     * @method Grid\Column|Collection should_display_on_index(string $label = null)
     * @method Grid\Column|Collection content(string $label = null)
     * @method Grid\Column|Collection entry_uuid(string $label = null)
     * @method Grid\Column|Collection tag(string $label = null)
     * @method Grid\Column|Collection email_verified_at(string $label = null)
     * @method Grid\Column|Collection image(string $label = null)
     * @method Grid\Column|Collection browse_num(string $label = null)
     * @method Grid\Column|Collection category_id(string $label = null)
     * @method Grid\Column|Collection account(string $label = null)
     * @method Grid\Column|Collection send_limit(string $label = null)
     */
    class Grid {}

    class MiniGrid extends Grid {}

    /**
     * @property Show\Field|Collection id
     * @property Show\Field|Collection name
     * @property Show\Field|Collection type
     * @property Show\Field|Collection version
     * @property Show\Field|Collection detail
     * @property Show\Field|Collection created_at
     * @property Show\Field|Collection updated_at
     * @property Show\Field|Collection is_enabled
     * @property Show\Field|Collection parent_id
     * @property Show\Field|Collection order
     * @property Show\Field|Collection icon
     * @property Show\Field|Collection uri
     * @property Show\Field|Collection extension
     * @property Show\Field|Collection permission_id
     * @property Show\Field|Collection menu_id
     * @property Show\Field|Collection slug
     * @property Show\Field|Collection http_method
     * @property Show\Field|Collection http_path
     * @property Show\Field|Collection role_id
     * @property Show\Field|Collection user_id
     * @property Show\Field|Collection value
     * @property Show\Field|Collection username
     * @property Show\Field|Collection password
     * @property Show\Field|Collection avatar
     * @property Show\Field|Collection email
     * @property Show\Field|Collection wx_openid
     * @property Show\Field|Collection remember_token
     * @property Show\Field|Collection task_id
     * @property Show\Field|Collection subtask_id
     * @property Show\Field|Collection nickname
     * @property Show\Field|Collection is_video
     * @property Show\Field|Collection comment
     * @property Show\Field|Collection status
     * @property Show\Field|Collection chain_id
     * @property Show\Field|Collection contract_address
     * @property Show\Field|Collection block_number
     * @property Show\Field|Collection time_stamp
     * @property Show\Field|Collection tx_hash
     * @property Show\Field|Collection block_hash
     * @property Show\Field|Collection nonce
     * @property Show\Field|Collection transaction_index
     * @property Show\Field|Collection from_address
     * @property Show\Field|Collection to_address
     * @property Show\Field|Collection gas
     * @property Show\Field|Collection gas_price
     * @property Show\Field|Collection cumulative_gas_used
     * @property Show\Field|Collection gas_used
     * @property Show\Field|Collection confirmations
     * @property Show\Field|Collection is_error
     * @property Show\Field|Collection txreceipt_status
     * @property Show\Field|Collection input
     * @property Show\Field|Collection method_id
     * @property Show\Field|Collection function_name
     * @property Show\Field|Collection uuid
     * @property Show\Field|Collection connection
     * @property Show\Field|Collection queue
     * @property Show\Field|Collection payload
     * @property Show\Field|Collection exception
     * @property Show\Field|Collection failed_at
     * @property Show\Field|Collection member_id
     * @property Show\Field|Collection amount
     * @property Show\Field|Collection contract_dynamic_id
     * @property Show\Field|Collection performance_record_id
     * @property Show\Field|Collection from_grab_id
     * @property Show\Field|Collection remark
     * @property Show\Field|Collection pid
     * @property Show\Field|Collection deep
     * @property Show\Field|Collection path
     * @property Show\Field|Collection address
     * @property Show\Field|Collection level
     * @property Show\Field|Collection performance
     * @property Show\Field|Collection total_earnings
     * @property Show\Field|Collection total_consumption
     * @property Show\Field|Collection total_grab_count
     * @property Show\Field|Collection token
     * @property Show\Field|Collection tokenable_type
     * @property Show\Field|Collection tokenable_id
     * @property Show\Field|Collection abilities
     * @property Show\Field|Collection last_used_at
     * @property Show\Field|Collection expires_at
     * @property Show\Field|Collection app_name
     * @property Show\Field|Collection admin_id
     * @property Show\Field|Collection attr_name
     * @property Show\Field|Collection attr_type
     * @property Show\Field|Collection attr_value
     * @property Show\Field|Collection sort
     * @property Show\Field|Collection account_id
     * @property Show\Field|Collection query_name
     * @property Show\Field|Collection match_name
     * @property Show\Field|Collection replys
     * @property Show\Field|Collection sequence
     * @property Show\Field|Collection batch_id
     * @property Show\Field|Collection family_hash
     * @property Show\Field|Collection should_display_on_index
     * @property Show\Field|Collection content
     * @property Show\Field|Collection entry_uuid
     * @property Show\Field|Collection tag
     * @property Show\Field|Collection email_verified_at
     * @property Show\Field|Collection image
     * @property Show\Field|Collection browse_num
     * @property Show\Field|Collection category_id
     * @property Show\Field|Collection account
     * @property Show\Field|Collection send_limit
     *
     * @method Show\Field|Collection id(string $label = null)
     * @method Show\Field|Collection name(string $label = null)
     * @method Show\Field|Collection type(string $label = null)
     * @method Show\Field|Collection version(string $label = null)
     * @method Show\Field|Collection detail(string $label = null)
     * @method Show\Field|Collection created_at(string $label = null)
     * @method Show\Field|Collection updated_at(string $label = null)
     * @method Show\Field|Collection is_enabled(string $label = null)
     * @method Show\Field|Collection parent_id(string $label = null)
     * @method Show\Field|Collection order(string $label = null)
     * @method Show\Field|Collection icon(string $label = null)
     * @method Show\Field|Collection uri(string $label = null)
     * @method Show\Field|Collection extension(string $label = null)
     * @method Show\Field|Collection permission_id(string $label = null)
     * @method Show\Field|Collection menu_id(string $label = null)
     * @method Show\Field|Collection slug(string $label = null)
     * @method Show\Field|Collection http_method(string $label = null)
     * @method Show\Field|Collection http_path(string $label = null)
     * @method Show\Field|Collection role_id(string $label = null)
     * @method Show\Field|Collection user_id(string $label = null)
     * @method Show\Field|Collection value(string $label = null)
     * @method Show\Field|Collection username(string $label = null)
     * @method Show\Field|Collection password(string $label = null)
     * @method Show\Field|Collection avatar(string $label = null)
     * @method Show\Field|Collection email(string $label = null)
     * @method Show\Field|Collection wx_openid(string $label = null)
     * @method Show\Field|Collection remember_token(string $label = null)
     * @method Show\Field|Collection task_id(string $label = null)
     * @method Show\Field|Collection subtask_id(string $label = null)
     * @method Show\Field|Collection nickname(string $label = null)
     * @method Show\Field|Collection is_video(string $label = null)
     * @method Show\Field|Collection comment(string $label = null)
     * @method Show\Field|Collection status(string $label = null)
     * @method Show\Field|Collection chain_id(string $label = null)
     * @method Show\Field|Collection contract_address(string $label = null)
     * @method Show\Field|Collection block_number(string $label = null)
     * @method Show\Field|Collection time_stamp(string $label = null)
     * @method Show\Field|Collection tx_hash(string $label = null)
     * @method Show\Field|Collection block_hash(string $label = null)
     * @method Show\Field|Collection nonce(string $label = null)
     * @method Show\Field|Collection transaction_index(string $label = null)
     * @method Show\Field|Collection from_address(string $label = null)
     * @method Show\Field|Collection to_address(string $label = null)
     * @method Show\Field|Collection gas(string $label = null)
     * @method Show\Field|Collection gas_price(string $label = null)
     * @method Show\Field|Collection cumulative_gas_used(string $label = null)
     * @method Show\Field|Collection gas_used(string $label = null)
     * @method Show\Field|Collection confirmations(string $label = null)
     * @method Show\Field|Collection is_error(string $label = null)
     * @method Show\Field|Collection txreceipt_status(string $label = null)
     * @method Show\Field|Collection input(string $label = null)
     * @method Show\Field|Collection method_id(string $label = null)
     * @method Show\Field|Collection function_name(string $label = null)
     * @method Show\Field|Collection uuid(string $label = null)
     * @method Show\Field|Collection connection(string $label = null)
     * @method Show\Field|Collection queue(string $label = null)
     * @method Show\Field|Collection payload(string $label = null)
     * @method Show\Field|Collection exception(string $label = null)
     * @method Show\Field|Collection failed_at(string $label = null)
     * @method Show\Field|Collection member_id(string $label = null)
     * @method Show\Field|Collection amount(string $label = null)
     * @method Show\Field|Collection contract_dynamic_id(string $label = null)
     * @method Show\Field|Collection performance_record_id(string $label = null)
     * @method Show\Field|Collection from_grab_id(string $label = null)
     * @method Show\Field|Collection remark(string $label = null)
     * @method Show\Field|Collection pid(string $label = null)
     * @method Show\Field|Collection deep(string $label = null)
     * @method Show\Field|Collection path(string $label = null)
     * @method Show\Field|Collection address(string $label = null)
     * @method Show\Field|Collection level(string $label = null)
     * @method Show\Field|Collection performance(string $label = null)
     * @method Show\Field|Collection total_earnings(string $label = null)
     * @method Show\Field|Collection total_consumption(string $label = null)
     * @method Show\Field|Collection total_grab_count(string $label = null)
     * @method Show\Field|Collection token(string $label = null)
     * @method Show\Field|Collection tokenable_type(string $label = null)
     * @method Show\Field|Collection tokenable_id(string $label = null)
     * @method Show\Field|Collection abilities(string $label = null)
     * @method Show\Field|Collection last_used_at(string $label = null)
     * @method Show\Field|Collection expires_at(string $label = null)
     * @method Show\Field|Collection app_name(string $label = null)
     * @method Show\Field|Collection admin_id(string $label = null)
     * @method Show\Field|Collection attr_name(string $label = null)
     * @method Show\Field|Collection attr_type(string $label = null)
     * @method Show\Field|Collection attr_value(string $label = null)
     * @method Show\Field|Collection sort(string $label = null)
     * @method Show\Field|Collection account_id(string $label = null)
     * @method Show\Field|Collection query_name(string $label = null)
     * @method Show\Field|Collection match_name(string $label = null)
     * @method Show\Field|Collection replys(string $label = null)
     * @method Show\Field|Collection sequence(string $label = null)
     * @method Show\Field|Collection batch_id(string $label = null)
     * @method Show\Field|Collection family_hash(string $label = null)
     * @method Show\Field|Collection should_display_on_index(string $label = null)
     * @method Show\Field|Collection content(string $label = null)
     * @method Show\Field|Collection entry_uuid(string $label = null)
     * @method Show\Field|Collection tag(string $label = null)
     * @method Show\Field|Collection email_verified_at(string $label = null)
     * @method Show\Field|Collection image(string $label = null)
     * @method Show\Field|Collection browse_num(string $label = null)
     * @method Show\Field|Collection category_id(string $label = null)
     * @method Show\Field|Collection account(string $label = null)
     * @method Show\Field|Collection send_limit(string $label = null)
     */
    class Show {}

    /**
     * @method \Dcat\Admin\Form\Extend\Distpicker\Form\Distpicker distpicker(...$params)
     * @method \Dcat\Admin\Form\Extend\Diyforms\Form\DiyForm diyForm(...$params)
     * @method \Dcat\Admin\Form\Extend\FormMedia\Form\Field\Iconimg iconimg(...$params)
     * @method \Dcat\Admin\Form\Extend\FormMedia\Form\Field\Photo photo(...$params)
     * @method \Dcat\Admin\Form\Extend\FormMedia\Form\Field\Photos photos(...$params)
     * @method \Dcat\Admin\Form\Extend\FormMedia\Form\Field\Video video(...$params)
     * @method \Dcat\Admin\Form\Extend\FormMedia\Form\Field\Audio audio(...$params)
     * @method \Dcat\Admin\Form\Extend\FormMedia\Form\Field\Files files(...$params)
     */
    class Form {}

}

namespace Dcat\Admin\Grid {
    /**
     * @method $this distpicker(...$params)
     */
    class Column {}

    /**
     * @method \Dcat\Admin\Form\Extend\Distpicker\Filter\DistpickerFilter distpicker(...$params)
     */
    class Filter {}
}

namespace Dcat\Admin\Show {
    /**
     * @method $this diyForm(...$params)
     */
    class Field {}
}
