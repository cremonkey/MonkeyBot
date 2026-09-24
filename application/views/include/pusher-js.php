<script src="https://js.pusher.com/7.2/pusher.min.js"></script>
<?php $this->config->load('pusher');
  $media_text = '';
  $mediaType =$this->using_media_type ?? 'fb';
  if($mediaType == 'fb'){
    $media_text = 'Facebook';
  }
  else{
    $media_text = 'Instagram';
  }
  $get_real_user_id = $this->session->userdata("real_user_id");
  // Private, per-tenant, ids-only realtime (see livechat_realtime_helper.php). NULL when
  // Pusher is unconfigured -> subscribe below is skipped and the page behaves as before.
  $this->load->helper('inbox_realtime');
  $this->load->helper('livechat_realtime');
  $livechat_realtime = livechat_realtime_client_config($this, $get_real_user_id ?: $this->session->userdata('user_id'));
?>
<script>
  var get_real_user_id = "<?php echo $get_real_user_id;?>";
  // Enable pusher logging - don't include this in production
  Pusher.logToConsole = false;
  var LIVECHAT_RT = <?php echo json_encode($livechat_realtime);?>;
  var LIVECHAT_CSRF = "<?php echo $this->session->userdata('csrf_token_session'); ?>";
  var pusher = LIVECHAT_RT ? new Pusher(LIVECHAT_RT.key, {
    cluster: LIVECHAT_RT.cluster || undefined,
    authEndpoint: '<?php echo site_url('livechat/realtime_auth');?>',
    auth: {params: {csrf_token: LIVECHAT_CSRF}}
  }) : null;
  var isNotitificationEnabled = '<?php echo $browser_notification_enabled ?? '0';?>';
  var timezone = '<?php echo !empty($this->config->item("time_zone")) ? $this->config->item("time_zone") : 'Europe/Dublin';?>';
  var messageType = '<?php echo $message_type ?? 'all';?>';
  if(messageType=='') messageType = 'all';
  var route_name = '<?php echo current_url();?>';

  // Ids-only event: no message content or rendered HTML travels over Pusher any more. We
  // refresh the conversation list (existing tenant-scoped endpoint) and, if the affected
  // subscriber's conversation is open, fetch the new message(s) by id through the existing
  // get_conversation_single endpoint (same one the page already polls with on load).
  var livechatChannel = (pusher && LIVECHAT_RT) ? pusher.subscribe(LIVECHAT_RT.channel) : null;
  if (livechatChannel) {
    livechatChannel.bind('livechat_event', function(responseData) {
      if (!responseData) return;
      var subscribe_id = responseData.subscriber_id;
      var page_id = responseData.page_id;
      var message_id = responseData.message_id;
      var sender = responseData.sender;

      if(messageType != 'archived' && sender=='user'){
        $('#chatNotificationAudio')[0].play();
      }

      var selectedSubscriber = $('.open_conversation.bg-light').attr('from_user_id');

      var matchingSubscriberElement = $('.open_conversation[from_user_id='+subscribe_id+']');
      if(matchingSubscriberElement.length==0){
        $("#refresh_data").trigger('click');
        return false;
      }

      // Refresh the list (preview text, badge, ordering) via the existing endpoint; no
      // message content is carried in the realtime payload itself.
      $("#refresh_data").trigger('click');

      if(subscribe_id == selectedSubscriber){
        $.ajax({
          url: '<?php echo site_url('livechat/get_conversation_single');?>',
          type: 'POST',
          data: { from_user_id: subscribe_id, whatsapp_bot_id: page_id, last_message_id: (parseInt(message_id, 10) - 1) },
          success: function(html){
            if(!html) return;
            $("#conversation_modal_body").append(html);
            setTimeout(function(){
              var element = document.getElementById("conversation_modal_body");
              element.scrollTop = element.scrollHeight;
            }, 200);
          }
        });
      }
    });

    livechatChannel.bind('livechat_assigned_agent_event', function(responseData) {
      if (!responseData) return;
      var social_media = responseData.social_media;
      var subscribe_id = responseData.subscriber_id;
      var from_type = social_media=='ig' ? 'Instagram' : 'Messenger';
      $('#agentAssignNotificationAudio')[0].play();
      if(route_name == '<?php echo base_url('dashboard');?>'){
        var notificationUrl = '<?php echo base_url('livechat/load_livechat');?>'+'?subscriber_id='+subscribe_id+"&media_type="+social_media;
        if(isNotitificationEnabled=='1'){
          Notification.requestPermission().then(perm=>{
            if(perm == 'granted'){
              const notification = new Notification("<?php echo $this->lang->line('You have been assigned a new conversation');?>",{
                body: from_type,
                icon: "<?php echo site_url('assets/images/media/' . $media_text . '.png');?>"
              });
              notification.onclick = () => {
                  notification.close();
                  window.open(notificationUrl, '_blank');
              }
            }
          });
        }
      }
    });
  }

  $(document).on('click', '#notification_action', function(event) {
    event.preventDefault();
    var state = $(this).data('state');
    if(state=='0'){// enable notification
        Notification.requestPermission().then(perm=>{
        if(Notification.permission == "granted") notification_action('1');
        else if(Notification.permission !== "denied"){
          Notification.requestPermission().then((perm) => {
            if(perm === "granted") notification_action('1');
            else iziToast.warning({title: 'Warning',message:'<?php echo $this->lang->line('Please allow browser notification permission to get inbox notification.');?>',position: 'bottomRight'});
          });
        }
        else iziToast.warning({title: 'Warning',message:'<?php echo $this->lang->line('Notification permission is blocked by your browser. Please allow notification permission for this site manually to get inbox notification.');?>',position: 'bottomRight'});
      });
    }
    else notification_action('0');
  });

  function notification_action(action)
  {
    $.ajax({
        url:"<?php echo site_url();?>livechat/update_browser_permission",
        type:'POST',
        data:{action},
        beforeSend: function (xhr) {
          xhr.setRequestHeader('X-CSRF-TOKEN', <?php echo $this->security->get_csrf_hash();?>);
        },
        success:function(response){
          location.reload();
        }
    });
  }

  function convertTZ(date, tzString) {
      date = date.replace( /[-]/g, '/')+' +0000';
      date = new Date((typeof date === "string" ? new Date(date) : date).toLocaleString("en-US", {timeZone: tzString}));
      var converted = date.toLocaleTimeString("en-US", {
          hour: "2-digit",
          minute: "2-digit",
          hour12: true,
      });
    return converted;
  }
</script>