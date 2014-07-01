/*
# php-dlna v1.0 - UPnP SSDP
# Copyright 2014 Torbjørn Tyridal (phpdlna@tyridal.no)
#
# This file is part of php-dlna.
#
#   php-dlna is free software: you can redistribute it and/or modify
#   it under the terms of the GNU Affero General Public License version 3
#   as published by the Free Software Foundation
#
#   php-dlna is distributed in the hope that it will be useful,
#   but WITHOUT ANY WARRANTY; without even the implied warranty of
#   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#   GNU Affero General Public License for more details.
#
#   You can get a copy The GNU Affero General Public license from
#   http://www.gnu.org/licenses/agpl-3.0.html


compile with: g++ -Wall -Wextra -o announce announce.cpp

*/

#include <sys/socket.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <unistd.h>

#include <csignal>
#include <climits>
#include <string>
#include <sstream>
#include <vector>
#include <iostream>

#include <cerrno>
#include <cstring>
#include <stdexcept>

#include "config.h"

const short upnp_broadcast_port=1900;
const char upnp_broadcast_addr[]="239.255.255.250";

// client will typically disconnect (even just stop playing
// the current media) if we do not reannouce within this time.
const unsigned cache_age = 60*60*12; //s == 12 hours

bool log_verbose = false;
bool is_daemon = false;

class Formatter
{
public:
    Formatter() {}
    ~Formatter() {}

    template <typename Type>
    Formatter & operator << (const Type & value) {
        stream_ << value;
        return *this;
    }

    std::string str() const         { return stream_.str(); }
    operator std::string () const   { return stream_.str(); }

    enum ConvertToString {
        to_str
    };
    std::string operator >> (ConvertToString) { return stream_.str(); }

private:
    std::stringstream stream_;

    Formatter(const Formatter &);
    Formatter & operator = (Formatter &);
};


class socket_error : public std::runtime_error {
public:
    int code;
    explicit socket_error (const std::string& what_arg)
        :runtime_error( Formatter() << what_arg << " " << errno << " - " << strerror(errno))
        ,code(errno)
        { }
};

class Socket {
public:
    int sd;
    Socket(int domain, int type, int protocol = 0):sd(-1) {
        sd = ::socket(domain, type, protocol);
        if(sd < 0) {
            throw socket_error("create socket failed");
        }
    }
    ~Socket() {
        if (sd!=-1) close(sd);
    }
    void bind(int family, int port, in_addr_t addr) {
        sockaddr_in localSock = sockaddr_in();
        localSock.sin_family = family;
        localSock.sin_port = htons(port);
        localSock.sin_addr.s_addr = addr;
        if(::bind(sd, (sockaddr*)&localSock, sizeof(localSock))) {
            throw socket_error("Binding failed");
        }
    }
    void reuse_address() {
        int reuse = 1;
        setsockopt(SOL_SOCKET, SO_REUSEADDR, (char *)&reuse, sizeof(reuse));
    }
    void setsockopt(int level, int optname, const void * optval, socklen_t optlen) {
        if(::setsockopt(sd, level, optname, optval, optlen) < 0) {
            throw socket_error("setsockopt");
        }
    }
    void sendto( std::string & s, const sockaddr_in & dest_addr, int flags = 0 ) {
        if (::sendto(sd, s.c_str(), s.length(), flags, (const sockaddr*) &dest_addr, sizeof dest_addr) == -1) {
            throw socket_error("sendto");
        }
    }
    size_t recvfrom(void * buf, size_t len, int flags, sockaddr_in *src_addr) {
        socklen_t addrlen = sizeof *src_addr;
        ssize_t datalen = ::recvfrom(sd, buf, len, flags, (sockaddr*) src_addr, &addrlen);
        if (addrlen != sizeof *src_addr) throw std::runtime_error("recvfrom, addrlen size not expected");
        if (datalen==-1) throw socket_error("recvfrom");
        return datalen;
    }

    static in_addr_t localhost_in_addr() {
        char hostname[HOST_NAME_MAX+1];
        if (gethostname(hostname, sizeof hostname)) {
            throw socket_error("gethostname failed");
        }
        addrinfo * ainfo = NULL;
        addrinfo hints = addrinfo();
        hints.ai_family= AF_INET;
        hints.ai_socktype= SOCK_DGRAM;
        if (getaddrinfo(hostname, NULL, &hints, &ainfo)) {
            throw socket_error("getaddrinfo failed");
        }
        in_addr_t ret = ((sockaddr_in*)ainfo->ai_addr)->sin_addr.s_addr;
        freeaddrinfo(ainfo);
        return ret;
    }
    static std::string localhost_addr() {
        in_addr i = {localhost_in_addr()};
        return std::string(inet_ntoa(i));
    }
};

class unicast_socket {
    Socket sd;
    std::vector<std::string> services;
    unicast_socket():sd(AF_INET, SOCK_DGRAM) {
        services.push_back("upnp:rootdevice");
        services.push_back("urn:schemas-upnp-org:device:MediaServer:1");
        services.push_back("urn:schemas-upnp-org:service:ConnectionManager:1");
        services.push_back("urn:schemas-upnp-org:service:ContentDirectory:1");
    }
    ~unicast_socket() {
    }
    unicast_socket(const unicast_socket&);
    unicast_socket& operator=(const unicast_socket&);
public:
    static unicast_socket& get() {
         static unicast_socket sing;
         return sing;
    }
    void notify() {
        sockaddr_in localSock=sockaddr_in();
        localSock.sin_family = AF_INET;
        localSock.sin_port = htons(upnp_broadcast_port);
        localSock.sin_addr.s_addr = inet_addr(upnp_broadcast_addr);

        std::string base = Formatter() << "NOTIFY * HTTP/1.1\r\n" <<
                                          "HOST: " << upnp_broadcast_addr <<":"<< upnp_broadcast_port << "\r\n" <<
                                          "CACHE-CONTROL: max-age="<<cache_age<<"\r\n" <<
                                          "LOCATION: "<<server_location<<"\r\n" <<
                                          "SERVER: "<<server_id<<"\r\n";




        if (log_verbose)
            std::cout << "notify about services\n";
        std::string s = Formatter() << base << "NT: uuid:"<<device_uuid<<"\r\n"<<
                                               "USN: uuid:"<<device_uuid<<"\r\n"<<
                                               "NTS: ssdp:alive\r\n"<<
                                               "\r\n";
        sd.sendto(s, localSock);

        for(std::vector<std::string>::iterator it = services.begin(); it != services.end(); ++it) {
            std::string s = Formatter() << base << "NT: "<<*it<<"\r\n"<<
                                                   "USN: uuid:"<<device_uuid<<"::"<<*it<<"\r\n"<<
                                                   "NTS: ssdp:alive\r\n"<<
                                                   "\r\n";
            sd.sendto(s,localSock);
            if (log_verbose)
                std::cout << "  "<<*it <<"\n";
        }
    }
    void msearch_reply(sockaddr_in &src_addr) {
        std::string base = Formatter() << "HTTP/1.1 200 OK\r\n" <<
                                          "EXT:\r\n" <<
                                          "CACHE-CONTROL: max-age="<<cache_age<<"\r\n" <<
                                          "LOCATION: "<<server_location<<"\r\n" <<
                                          "SERVER: "<<server_id<<"\r\n";

        for(std::vector<std::string>::iterator it = services.begin(); it != services.end(); ++it) {
            std::string s = Formatter() << base <<
                    "ST: "<<*it<<"\r\n"<<
                    "USN: uuid:"<<device_uuid<<"::"<<*it<<"\r\n"<<
                    "\r\n";
            sd.sendto(s, src_addr);
        }
    }
};


class broadcast_socket {
    Socket sd;
    ip_mreq group;
public:
    broadcast_socket() :sd(AF_INET, SOCK_DGRAM) {
        sd.reuse_address();

        sd.bind(AF_INET, upnp_broadcast_port, INADDR_ANY);

        // Join the multicast group
        //  Note that this IP_ADD_MEMBERSHIP option must be
        // called for each local interface over which the multicast
        // datagrams are to be received.
        group.imr_multiaddr.s_addr = inet_addr(upnp_broadcast_addr);
        group.imr_interface.s_addr = Socket::localhost_in_addr();
        sd.setsockopt(IPPROTO_IP, IP_ADD_MEMBERSHIP, (char *)&group, sizeof(group));
        if (log_verbose)
            std::cout << "Adding multicast group...OK.\n";
    }
    ~broadcast_socket() {
            group.imr_interface.s_addr = inet_addr("0.0.0.0");
            sd.setsockopt(IPPROTO_IP, IP_DROP_MEMBERSHIP, (char *)&group, sizeof(group));
    }

    void read() {
        int datalen;
        char databuf[1024];

        sockaddr_in src_addr;

        datalen = sd.recvfrom(databuf, sizeof databuf, 0, &src_addr);
        if (std::string("M-SEARCH").compare(0, datalen, databuf, 8)==0)
            unicast_socket::get().msearch_reply(src_addr);

        databuf[17]=0;
        if (log_verbose && !is_daemon)
            std::cout << inet_ntoa(src_addr.sin_addr) << ":" << src_addr.sin_port << " > " << databuf << std::endl;
    }
};


sig_atomic_t exit_now=0;
sig_atomic_t send_notify=1;
void ctrlc_handler(int) {
    exit_now = 1;
}
void sigalrm_handler(int) {
    send_notify = 1;
}

int main(void)
{
    struct sigaction sigIntHandler;
    sigIntHandler.sa_handler = ctrlc_handler;
    sigemptyset(&sigIntHandler.sa_mask);
    sigIntHandler.sa_flags = 0;
    sigaction(SIGINT, &sigIntHandler, NULL);
    sigIntHandler.sa_handler = sigalrm_handler;
    sigaction(SIGALRM, &sigIntHandler, NULL);

    broadcast_socket bcast;
    while(!exit_now) {
        if (send_notify) {
            send_notify=0;
            unicast_socket::get().notify();
            alarm(cache_age/2);
        }
        try {
            bcast.read();
        } catch (const socket_error e) {
            if (e.code != EINTR) {
                std::cout << e.what() << std::endl;
                throw;
            }
        }
    }
    return 0;
}
