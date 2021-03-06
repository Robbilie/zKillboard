$(document).ready(function() {
    // Check to see if the user has ads enabled
    if ( $("iframe").length == 0 ) {
        //$("#adsensetop, #adsensebottom").html("<center><img src='/img/wreck.png'> <a href='/information/payments/'>Remove Ads?</a> <img src='/img/wreck.png'></center></br/>");
        //$("#adnag").hide();
    }

    if ($("[rel=tooltip]").length) {
        $("[rel=tooltip]").tooltip({
            placement: "bottom",
            animation: false
        });
    }

    //
    $('.dropdown-toggle').dropdown();
    $("abbr.timeago").timeago();
    $(".alert").alert()

        // Javascript to enable link to tab
        var url = document.location.toString();
    if (url.match('#')) {
        $('.nav-pills a[href=#'+url.split('#')[1]+']').tab('show') ;
    }

    // Change hash for page-reload
    $('.nav-pills a').on('shown', function (e) {
        window.location.hash = e.target.hash;
    })

    // hide #back-top first
    $("#back-top").hide();

    // fade in #back-top
    $(function () {
        $(window).scroll(function () {
            if ($(this).scrollTop() > 500) {
                $('#back-top').fadeIn();
            } else {
                $('#back-top').fadeOut();
            }
        });

        // scroll body to 0px on click
        $('#back-top a').click(function () {
            $('body,html').animate({
                scrollTop: 0
            }, 100);
            return false;
        });
    });

    //add the autocomplete search thing
    $('#searchbox').zz_search( function(data, event) { window.location = '/' + data.type + '/' + data.id + '/'; event.preventDefault(); } );

    //and for the tracker entity lookup
    $('#addentitybox').zz_search( function(data) { 
        $('#addentity input[name="entitymetadata"]').val(JSON.stringify(data));
        $('#addentity input[name="addentitybox"]').val(data.name);
        $('#addentity').submit();
    });

    // prevent firing of window.location in table rows if a link is clicked directly
    $('.killListRow a').click(function(e) {
        e.stopPropagation();
    });

    $('a.openMenu').click(function(e){
        $('.content').toggleClass('opened');
        $('.mobileNav').toggleClass('opened');
        e.preventDefault();
    });

    // auto show comments tab on detail page
    if(window.location.hash.match(/comment/)) {
        $('a[href="#comment"]').tab('show');
    }

    if (top !== self) {
        $("#iframed").modal('show');
    }

    $("#killmailurl").bind('paste', function(event) {
        console.log(event);
        setTimeout(sentCrestUrl, 1);
    });

      // setup websocket with callbacks
    var ws = new ReconnectingWebSocket('wss://zkillboard.com:2096/', '', {maxReconnectAttempts: 15});
    ws.onmessage = function(event) {
        wslog(event.data);
    };

});

var notificationMethod = toastr8Notify;
if("Notification" in window) {
    if (Notification.permission === "granted") {
        notificationMethod = htmlNotify;
    } else if(Notification.permission !== 'denied') {
        Notification.requestPermission(function (permission) {
            if (permission === "granted") {
                notificationMethod = htmlNotify;
            }
        });
    }
}

function toastr8Notify (data) {
    toastr8.github({title: data.title, message: data.iskStr + "<br><a href='" + data.url + "'>" + data.url + "</a>", imgURI: data.image, timeOut: 0, extendedTimeout: 0, iconClass: "none"});
};

function htmlNotify (data) {
    var notfi = new Notification(data.title, {
        body: data.iskStr,
        icon: data.image
    });
    notif.onclick = function () {
        location = data.url;
    };
}

function wslog(msg)
{
    if (msg == 'ping' || msg == 'pong') return;
    json = JSON.parse(msg);
    console.log(json);
    if (json.action == 'tqStatus') {
        tqStatus = json.tqStatus;
        tqCount = json.tqCount;
        if (tqStatus == 'OFFLINE') {
            html = '<span class="red">TQ ' + tqCount + "</span>";
        } else {
            html = '<span class="green">TQ ' + tqCount + "</span>";
        }
        $("#tqStatus").html(html);
        $("#lasthour").text(json.kills);
    } else if (json.action == 'reload') {
        setTimeout("location.reload();", (Math.random() * 60000));
    } else if (json.action == 'bigkill') {
        notificationMethod(json);
    }
}

function saveFitting(id) {
    $('#modalMessageBody').html('Saving fit....');
    $('#modalMessage').modal('show');

    var request = $.ajax({
        url: "/ccpsavefit/" + id + "/",
        type: "GET",
        dataType: "text"
    });

    request.done(function(msg) {
        $('#modalMessageBody').html(msg);
        $('#modalMessage').modal('show');
    });
}

$('body').on('touchstart.dropdown', '.dropdown-menu', function (e) { e.stopPropagation(); });

function hideSortStuff(doHide)
{
    if (doHide) $(".hide-when-sorted").hide();
    else $(".hide-when-sorted").show();
}

function sentCrestUrl() {
    str = $("#killmailurl").val();
    strSplit = str.split("/");
    killID = strSplit[4];
    hash = strSplit[5];
    a = ['/crestmail/', killID, '/', hash, '/'];
    url = a.join('');
    $.get(url);
}
